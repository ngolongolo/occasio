<?php

namespace App\Http\Controllers;

use App\Models\EventSettlement;
use App\Models\ParticipationPayment;
use App\Services\SnippePay;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SnippeWebhookController
{
    private const PAYMENT_EVENTS = ['payment.completed', 'payment.failed', 'payment.voided', 'payment.expired'];
    private const PAYOUT_EVENTS = ['payout.completed', 'payout.failed', 'payout.reversed'];

    public function __invoke(Request $request, SnippePay $snippe): JsonResponse
    {
        abort_unless($request->isJson(), 415, 'Snippe webhooks must use application/json.');
        abort_if((string) config('services.snippe.webhook_secret') === '', 503, 'SNIPPE_WEBHOOK_SECRET is not configured.');

        $event = $snippe->verifyWebhook($request);
        $eventId = (string) ($event['id'] ?? '');
        $eventType = (string) ($event['type'] ?? '');
        $apiVersion = (string) ($event['api_version'] ?? '');
        $headerEvent = (string) $request->header('X-Webhook-Event');
        $data = $event['data'] ?? null;

        abort_unless($eventId !== '' && $eventType !== '' && is_array($data), 422, 'Invalid Snippe webhook envelope.');
        abort_unless($apiVersion === '2026-01-25', 422, 'Unsupported Snippe API version.');
        abort_unless($headerEvent !== '' && hash_equals($eventType, $headerEvent), 422, 'Webhook event header mismatch.');

        if (in_array($eventType, self::PAYMENT_EVENTS, true)) {
            $this->handlePayment($event, $data, $eventType);
        } elseif (in_array($eventType, self::PAYOUT_EVENTS, true)) {
            $this->handlePayout($event, $data, $eventType);
        } else {
            abort(422, 'Unsupported Snippe webhook event.');
        }

        return response()->json(['received' => true, 'event_id' => $eventId]);
    }

    private function handlePayment(array $event, array $data, string $eventType): void
    {
        $providerReference = (string) ($data['reference'] ?? '');
        $internalReference = (string) data_get($data, 'metadata.okesheni_payment', '');
        abort_unless($providerReference !== '', 422, 'Missing payment reference.');

        $payment = ParticipationPayment::where('provider_payment_reference', $providerReference)->first();
        if (! $payment && $internalReference !== '') {
            $payment = ParticipationPayment::where('reference', $internalReference)->first();
        }
        abort_unless($payment, 404, 'Payment reference not found.');

        DB::transaction(function () use ($payment, $event, $data, $eventType, $providerReference): void {
            $locked = ParticipationPayment::whereKey($payment->id)->with('invitee.event')->lockForUpdate()->firstOrFail();
            if ($locked->provider_payment_reference === null) {
                $locked->provider_payment_reference = $providerReference;
            }
            abort_unless(hash_equals((string) $locked->provider_payment_reference, $providerReference), 422, 'Payment reference mismatch.');

            $amount = data_get($data, 'amount.value');
            $currency = (string) data_get($data, 'amount.currency', '');
            abort_unless(is_numeric($amount) && abs((float) $locked->amount - (float) $amount) < 0.01, 422, 'Payment amount mismatch.');
            abort_unless(hash_equals($locked->currency, $currency), 422, 'Payment currency mismatch.');

            if ($phone = data_get($data, 'customer.phone')) {
                abort_unless(preg_replace('/\D+/', '', (string) $phone) === $locked->msisdn, 422, 'Payment phone number mismatch.');
            }

            $completed = $eventType === 'payment.completed' && ($data['status'] ?? null) === 'completed';
            if ($eventType === 'payment.completed') {
                abort_unless($completed, 422, 'Completed payment event has an invalid status.');
            }
            if ($locked->status === 'superseded') {
                $locked->update([
                    'provider_payment_reference' => $providerReference,
                    'provider_remarks' => $completed
                        ? 'Payment completed after this request was replaced. Manual reconciliation is required.'
                        : ($data['failure_reason'] ?? 'A provider update was received for a replaced payment request.'),
                    'callback_payload' => $event,
                ]);

                return;
            }
            if ($locked->status === 'paid' && ! $completed) return;

            $locked->update([
                'provider_payment_reference' => $providerReference,
                'status' => $completed ? 'paid' : 'failed',
                'provider_payment_date' => $completed ? now()->toDateString() : null,
                'provider_remarks' => $data['failure_reason'] ?? data_get($data, 'external_reference'),
                'callback_payload' => $event,
                'paid_at' => $completed ? ($locked->paid_at ?? now()) : null,
                'failed_at' => $completed ? null : now(),
            ]);

            $invitee = $locked->invitee;
            $confirmation = $completed
                ? $invitee->event->confirmationStatusFor($invitee->rsvp_status, 'paid', $invitee->confirmation_status)
                : $invitee->confirmation_status;
            $invitee->update([
                'participation_payment_status' => $completed ? 'paid' : 'failed',
                'confirmation_status' => $confirmation,
                'access_pass_issued_at' => $completed && $confirmation === 'confirmed'
                    ? ($invitee->access_pass_issued_at ?? now())
                    : null,
            ]);
        });
    }

    private function handlePayout(array $event, array $data, string $eventType): void
    {
        $providerReference = (string) ($data['reference'] ?? '');
        $internalReference = (string) data_get($data, 'metadata.okesheni_settlement', '');
        abort_unless($providerReference !== '', 422, 'Missing payout reference.');

        $settlement = EventSettlement::where('provider_reference', $providerReference)->first();
        if (! $settlement && $internalReference !== '') {
            $settlement = EventSettlement::where('reference', $internalReference)->first();
        }
        abort_unless($settlement, 404, 'Payout reference not found.');

        DB::transaction(function () use ($settlement, $event, $data, $eventType, $providerReference): void {
            $locked = EventSettlement::whereKey($settlement->id)->lockForUpdate()->firstOrFail();
            if ($locked->provider_reference === null) {
                $locked->provider_reference = $providerReference;
            }
            abort_unless(hash_equals((string) $locked->provider_reference, $providerReference), 422, 'Payout reference mismatch.');

            $amount = data_get($data, 'amount.value');
            $currency = (string) data_get($data, 'amount.currency', '');
            abort_unless(is_numeric($amount) && abs((float) $locked->amount - (float) $amount) < 0.01, 422, 'Payout amount mismatch.');
            abort_unless(hash_equals($locked->currency, $currency), 422, 'Payout currency mismatch.');

            $completed = $eventType === 'payout.completed' && ($data['status'] ?? null) === 'completed';
            if ($eventType === 'payout.completed') {
                abort_unless($completed, 422, 'Completed payout event has an invalid status.');
            }
            if ($locked->status === 'reversed' || ($locked->status === 'paid' && $eventType === 'payout.failed')) return;

            $status = match ($eventType) {
                'payout.completed' => 'paid',
                'payout.reversed' => 'reversed',
                default => 'failed',
            };
            $locked->update([
                'provider_reference' => $providerReference,
                'status' => $status,
                'remarks' => $data['failure_reason'] ?? data_get($data, 'external_reference'),
                'callback_payload' => $event,
                'paid_at' => $completed ? ($locked->paid_at ?? now()) : $locked->paid_at,
                'failed_at' => $completed ? null : now(),
            ]);
        });
    }
}
