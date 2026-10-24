<?php

namespace App\Http\Controllers;

use App\Models\{Event, EventSettlement, ParticipationPayment};
use App\Services\SnippePay;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SettlementController
{
    private function own(Event $event): void
    {
        if ((int) $event->user_id === (int) auth()->id()) return;

        abort_unless(auth()->user()->isSuperAdmin(), 403);
        abort_unless(request()->isMethodSafe(), 403, 'System Admin oversight is read-only. Use an explicit admin control for changes.');
    }

    public function store(Request $request, Event $event, SnippePay $pay)
    {
        $this->own($event);
        abort_unless($event->participation_payment_timing === 'pay_now' && $event->participation_collection_method === 'bluetick_pay', 404);
        $event->loadMissing('user.organiserVerification');
        if (! $event->user?->canCollectParticipationPayments()) {
            throw ValidationException::withMessages([
                'amount' => 'Settlement is paused until the organiser and payout account name are verified.',
            ]);
        }

        $request->merge(['use_other_account' => $request->boolean('use_other_account')]);
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:5000'],
            'use_other_account' => ['required', 'boolean'],
            'payment_type' => ['nullable', 'required_if:use_other_account,true', 'in:MixxByYas,Mpesa,AirtelMoney,Ecobank'],
            'payment_number' => ['nullable', 'required_if:use_other_account,true', 'string', 'max:50'],
            'account_name' => ['nullable', 'required_if:use_other_account,true', 'string', 'max:180'],
        ]);

        $useOtherAccount = (bool) $data['use_other_account'];
        if (! $useOtherAccount && (! $event->payout_payment_type || ! trim((string) $event->payout_number))) {
            throw ValidationException::withMessages(['payment_number' => 'Configure a default settlement account or select another account.']);
        }

        $paymentType = $useOtherAccount ? $data['payment_type'] : $event->payout_payment_type;
        $paymentNumber = trim((string) ($useOtherAccount ? $data['payment_number'] : $event->payout_number));
        $accountName = trim((string) ($useOtherAccount ? $data['account_name'] : $event->host));
        if ($paymentType === 'Ecobank') {
            $paymentNumber = preg_replace('/\s+/', '', $paymentNumber);
            if ($paymentNumber === '') throw ValidationException::withMessages(['payment_number' => 'Enter the Ecobank account number.']);
        } else {
            try {
                $paymentNumber = $pay->normalizeMsisdn($paymentNumber);
            } catch (ValidationException $exception) {
                throw ValidationException::withMessages(['payment_number' => collect($exception->errors())->flatten()->first()]);
            }
        }

        $settlement = DB::transaction(function () use ($event, $data, $paymentType, $paymentNumber, $accountName) {
            $collected = (float) ParticipationPayment::whereHas('invitee', fn ($query) => $query->where('event_id', $event->id))
                ->where('status', 'paid')->lockForUpdate()->sum('amount');
            $reserved = (float) $event->settlements()->whereIn('status', ['initiated', 'pending', 'paid'])->sum('amount');
            $available = max(0, $collected - $reserved);
            if ((float) $data['amount'] > $available) {
                throw ValidationException::withMessages(['amount' => 'The withdrawal exceeds the available balance of '.$event->participation_currency.' '.number_format($available, 2).'.']);
            }

            return $event->settlements()->create([
                'requested_by' => auth()->id(),
                'reference' => 'OSP'.now()->format('ymd').strtoupper(Str::random(10)),
                'callback_token' => Str::random(32),
                'amount' => $data['amount'],
                'currency' => $event->participation_currency,
                'payment_type' => $paymentType,
                'payment_number' => $paymentNumber,
                'account_name' => $accountName,
            ]);
        });

        try {
            $body = $pay->requestPayout($settlement);
            $settlement->refresh();
            $terminal = in_array($settlement->status, ['paid', 'failed', 'reversed'], true);
            $status = $terminal ? $settlement->status : (data_get($body, 'data.status') === 'completed' ? 'paid' : 'pending');
            $settlement->update([
                'status' => $status,
                'provider_reference' => data_get($body, 'data.reference'),
                'request_response' => $body,
                'requested_at' => now(),
                'paid_at' => $status === 'paid' ? ($settlement->paid_at ?? now()) : $settlement->paid_at,
                'failed_at' => in_array($status, ['failed', 'reversed'], true) ? ($settlement->failed_at ?? now()) : null,
            ]);
        } catch (\Throwable $exception) {
            $settlement->update(['status' => 'failed', 'remarks' => mb_substr($exception->getMessage(), 0, 1000), 'failed_at' => now()]);
            throw ValidationException::withMessages(['amount' => 'Snippe could not accept the withdrawal request. Please try again.']);
        }

        return back()->with('success', 'Withdrawal request submitted to the selected settlement account.');
    }

    public function callback(Request $request, string $token, SnippePay $pay)
    {
        $settlement = EventSettlement::where('callback_token', $token)->firstOrFail();
        $event = $pay->verifyWebhook($request);
        $data = (array) data_get($event, 'data', []);
        $type = (string) ($event['type'] ?? '');
        $amount = data_get($data, 'amount.value');
        $currency = (string) data_get($data, 'amount.currency', '');
        abort_unless(in_array($type, ['payout.completed', 'payout.failed', 'payout.reversed'], true), 422, 'Unsupported payout event.');
        abort_unless($settlement->provider_reference && hash_equals($settlement->provider_reference, (string) ($data['reference'] ?? '')), 422, 'Settlement reference mismatch.');
        abort_unless(is_numeric($amount) && abs((float) $settlement->amount - (float) $amount) < .01 && hash_equals($settlement->currency, $currency), 422, 'Settlement amount mismatch.');
        $success = $type === 'payout.completed' && ($data['status'] ?? null) === 'completed';
        $settlement->update([
            'status' => $success ? 'paid' : 'failed',
            'remarks' => $data['failure_reason'] ?? data_get($data, 'external_reference'),
            'callback_payload' => $event,
            'paid_at' => $success ? now() : null,
            'failed_at' => $success ? null : now(),
        ]);
        return response()->json(['received' => true]);
    }
}
