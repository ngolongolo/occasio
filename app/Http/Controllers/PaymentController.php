<?php

namespace App\Http\Controllers;

use App\Models\{Invitee, ParticipationPayment};
use App\Services\CheckoutDisclosure;
use App\Services\EventPricingService;
use App\Services\ParticipationPaymentService;
use App\Services\SnippePay;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PaymentController
{
    public function show(string $token, EventPricingService $pricing)
    {
        $guest = Invitee::where('token', $token)->with(['event', 'eventPackage', 'latestParticipationPayment'])->firstOrFail();
        $event = $guest->event;
        abort_unless($event->participation_enabled && $event->participation_payment_timing === 'pay_now' && $guest->rsvp_status === 'accepted', 404);
        $quote = $pricing->quoteForInvitee($guest);
        return response()->view('events.payment', compact('guest', 'event', 'quote'))->header('Cache-Control', 'private, no-store, max-age=0');
    }

    public function status(string $token, SnippePay $snippe)
    {
        $guest = Invitee::where('token', $token)->with(['event', 'latestParticipationPayment'])->firstOrFail();
        $payment = $guest->latestParticipationPayment;
        $checkMessage = null;

        if ($payment?->status === 'pending' && $payment->provider_payment_reference) {
            try {
                $this->syncPaymentStatus($payment, $snippe->paymentStatus($payment));
                $guest->refresh()->load('latestParticipationPayment');
                $payment = $guest->latestParticipationPayment;
            } catch (ValidationException $exception) {
                $checkMessage = collect($exception->errors())->flatten()->first();
            }
        }

        $confirmed = $guest->accessPassEligible();
        $message = $guest->participation_payment_status === 'payment_reported'
            ? 'Your payment reference was submitted. The organiser is checking it.'
            : match ($payment?->status) {
                'paid' => $confirmed ? 'Payment confirmed. Your access pass is ready.' : 'Payment confirmed. Your attendance is still awaiting organiser approval.',
                'request_failed' => $payment->provider_remarks ?: 'The payment request could not be created. Return to attendance details and try again.',
                'failed' => $payment->provider_remarks ?: 'The payment was not completed. Return to attendance details and try again.',
                'pending' => $checkMessage ?: 'Still waiting for confirmation. Complete the prompt on your phone.',
                default => $checkMessage ?: 'Return to attendance details to start payment.',
            };

        return response()->json([
            'status' => $guest->participation_payment_status,
            'confirmed' => $confirmed,
            'payment_status' => $payment?->status,
            'message' => $message,
            'updated_at' => $payment?->updated_at?->toIso8601String(),
            'redirect' => $confirmed ? route('rsvp.payment.show', $guest->token) : null,
        ])->header('Cache-Control', 'private, no-store, max-age=0');
    }

    public function store(
        Request $request,
        string $token,
        ParticipationPaymentService $payments,
        CheckoutDisclosure $disclosures,
        EventPricingService $pricing,
    )
    {
        $guest = Invitee::where('token', $token)->with('event')->firstOrFail();
        $event = $guest->event;
        abort_unless($event->participation_enabled && $event->participation_payment_timing === 'pay_now' && $event->participation_collection_method === 'bluetick_pay', 404);
        abort_if($event->responsesClosed(), 410, 'Payments are closed because this event has started.');
        abort_unless($guest->rsvp_status === 'accepted', 422, 'Confirm that you are attending before making payment.');
        if ($event->rsvp_confirmation_mode === 'organizer_approval' && $guest->confirmation_status !== 'awaiting_payment') {
            throw ValidationException::withMessages(['msisdn' => 'The organiser must approve this attendance request before payment can begin.']);
        }

        $request->merge([
            'attending_count' => $request->input('attending_count', $guest->attending_count),
            'event_package_id' => $request->input('event_package_id', $guest->event_package_id),
        ]);
        $data = $request->validate([
            'attending_count' => ['required', 'integer', 'min:1', 'max:'.max(1, (int) $guest->max_guests)],
            'event_package_id' => [$event->usesPackages() ? 'required' : 'nullable', 'nullable', 'integer'],
            'msisdn' => ['required', 'string', 'max:30'],
            'checkout_acknowledged' => ['required', 'accepted'],
        ]);
        [$guest, $acknowledgement] = DB::transaction(function () use ($guest, $data, $pricing, $disclosures): array {
            $lockedGuest = Invitee::whereKey($guest->id)->with(['event', 'eventPackage'])->lockForUpdate()->firstOrFail();
            $lockedEvent = $lockedGuest->event()->lockForUpdate()->firstOrFail();
            $lockedGuest->setRelation('event', $lockedEvent);
            $package = null;
            if ($lockedEvent->usesPackages()) {
                $requestedPackageId = (int) $data['event_package_id'];
                $sameSavedPackage = (int) $lockedGuest->event_package_id === $requestedPackageId
                    && $lockedGuest->hasPackageQuoteSnapshot();
                $package = $sameSavedPackage
                    ? $lockedEvent->packages()->whereKey($requestedPackageId)->first()
                    : $pricing->resolvePackage($lockedEvent, $requestedPackageId);
            }

            $countChanged = (int) $data['attending_count'] !== (int) $lockedGuest->attending_count;
            $packageChanged = $lockedEvent->usesPackages()
                && (int) $data['event_package_id'] !== (int) $lockedGuest->event_package_id;
            $activePayment = $lockedGuest->participationPayments()
                ->whereIn('status', ['initiated', 'pending'])
                ->lockForUpdate()
                ->exists();
            if ($activePayment) {
                throw ValidationException::withMessages([
                    $packageChanged ? 'event_package_id' : 'msisdn' => $countChanged || $packageChanged
                        ? 'Your current payment request is still being processed. Check its status before changing the package or party size.'
                        : 'A payment request is already being processed. Complete it or check its status before trying again.',
                ]);
            }
            if ($lockedEvent->rsvp_confirmation_mode === 'organizer_approval' && ($countChanged || $packageChanged)) {
                throw ValidationException::withMessages([
                    $packageChanged ? 'event_package_id' : 'attending_count' => 'The organiser approved a different package or party size. Update attendance and request approval again.',
                ]);
            }
            if ($countChanged || $packageChanged) {
                $lockedGuest->participationPayments()
                    ->whereIn('status', ['failed', 'request_failed'])
                    ->update(['status' => 'superseded']);
            }

            $pricing->assignPackage($lockedGuest, $package, (int) $data['attending_count']);
            $lockedGuest->refresh()->load('event');
            $acknowledgement = $disclosures->acknowledge($lockedGuest, (int) $data['attending_count']);

            return [$lockedGuest, $acknowledgement];
        });
        $payment = $payments->initiate($guest, $data['msisdn'], (int) $data['attending_count'], $acknowledgement);
        return back()->with('success', 'Payment request sent to '.$payment->msisdn.'. Complete the prompt on your phone.');
    }

    public function callback(Request $request, string $token, SnippePay $snippe)
    {
        $payment = ParticipationPayment::where('callback_token', $token)->with('invitee.event')->firstOrFail();
        $event = $snippe->verifyWebhook($request);
        $data = (array) data_get($event, 'data', []);
        $providerReference = (string) ($data['reference'] ?? '');
        $amount = data_get($data, 'amount.value');
        $currency = (string) data_get($data, 'amount.currency', '');
        abort_unless(in_array((string) ($event['type'] ?? ''), ['payment.completed', 'payment.failed', 'payment.voided', 'payment.expired'], true), 422, 'Unsupported payment event.');
        abort_unless($payment->provider_payment_reference && hash_equals($payment->provider_payment_reference, $providerReference), 422, 'Reference mismatch.');
        abort_unless(is_numeric($amount) && abs((float) $payment->amount - (float) $amount) < 0.01, 422, 'Amount mismatch.');
        abort_unless(hash_equals($payment->currency, $currency), 422, 'Currency mismatch.');
        if ($phone = data_get($data, 'customer.phone')) abort_unless(preg_replace('/\D+/', '', (string) $phone) === $payment->msisdn, 422, 'MSISDN mismatch.');

        $successful = ($event['type'] ?? null) === 'payment.completed' && ($data['status'] ?? null) === 'completed';
        DB::transaction(function () use ($payment, $event, $data, $successful) {
            $locked = ParticipationPayment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if (in_array($locked->status, ['paid', 'superseded'], true)) return;
            $locked->update([
                'status' => $successful ? 'paid' : 'failed',
                'provider_payment_date' => $successful ? now()->toDateString() : null,
                'provider_remarks' => $data['failure_reason'] ?? data_get($data, 'external_reference'),
                'callback_payload' => $event, 'paid_at' => $successful ? now() : null, 'failed_at' => $successful ? null : now(),
            ]);
            $invitee = $locked->invitee;
            $invitee->update([
                'participation_payment_status' => $successful ? 'paid' : 'failed',
                'confirmation_status' => $successful ? $invitee->event->confirmationStatusFor($invitee->rsvp_status,'paid',$invitee->confirmation_status) : $invitee->confirmation_status,
                'access_pass_issued_at' => $successful && $invitee->event->confirmationStatusFor($invitee->rsvp_status,'paid',$invitee->confirmation_status) === 'confirmed' ? ($invitee->access_pass_issued_at ?? now()) : null,
            ]);
        });
        return response()->json(['received' => true]);
    }

    private function syncPaymentStatus(ParticipationPayment $payment, array $body): void
    {
        $data = (array) data_get($body, 'data', []);
        $reference = (string) ($data['reference'] ?? '');
        if (! $payment->provider_payment_reference || ! hash_equals($payment->provider_payment_reference, $reference)) {
            throw ValidationException::withMessages(['payment' => 'Snippe returned a different payment reference.']);
        }

        $providerStatus = strtolower((string) ($data['status'] ?? ''));
        if ($providerStatus === 'completed') {
            $amount = data_get($data, 'amount.value');
            $currency = (string) data_get($data, 'amount.currency', '');
            if (! is_numeric($amount) || abs((float) $payment->amount - (float) $amount) >= 0.01 || ! hash_equals($payment->currency, $currency)) {
                throw ValidationException::withMessages(['payment' => 'Snippe returned payment details that do not match this attendance payment.']);
            }

            DB::transaction(function () use ($payment, $body): void {
                $locked = ParticipationPayment::whereKey($payment->id)->lockForUpdate()->with('invitee.event')->firstOrFail();
                if (in_array($locked->status, ['paid', 'superseded'], true)) return;
                $locked->update([
                    'status' => 'paid',
                    'request_response' => $body,
                    'provider_payment_date' => now()->toDateString(),
                    'provider_remarks' => null,
                    'paid_at' => now(),
                    'failed_at' => null,
                ]);
                $invitee = $locked->invitee;
                $confirmation = $invitee->event->confirmationStatusFor($invitee->rsvp_status, 'paid', $invitee->confirmation_status);
                $invitee->update([
                    'participation_payment_status' => 'paid',
                    'confirmation_status' => $confirmation,
                    'access_pass_issued_at' => $confirmation === 'confirmed' ? ($invitee->access_pass_issued_at ?? now()) : null,
                ]);
            });
            return;
        }

        if (in_array($providerStatus, ['failed', 'expired', 'cancelled', 'canceled', 'voided'], true)) {
            $message = (string) (data_get($data, 'failure_reason') ?: 'Snippe reported this payment as '.$providerStatus.'.');
            DB::transaction(function () use ($payment, $body, $message): void {
                $locked = ParticipationPayment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
                if (in_array($locked->status, ['paid', 'superseded'], true)) return;
                $locked->update(['status' => 'failed', 'request_response' => $body, 'provider_remarks' => $message, 'failed_at' => now()]);
                $locked->invitee()->update(['participation_payment_status' => 'failed']);
            });
        }
    }
}
