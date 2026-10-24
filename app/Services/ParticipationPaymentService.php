<?php

namespace App\Services;

use App\Models\Invitee;
use App\Models\CheckoutAcknowledgement;
use App\Models\ParticipationPayment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ParticipationPaymentService
{
    private const MAX_PAYMENT_AMOUNT = 999999999999.99;

    public function __construct(
        private readonly SnippePay $snippe,
        private readonly CheckoutDisclosure $disclosures,
        private readonly EventPricingService $pricing,
    ) {}

    public function initiate(Invitee $guest, string $mobileNumber, ?int $attendingCount = null, ?CheckoutAcknowledgement $acknowledgement = null): ParticipationPayment
    {
        $event = $guest->event()->firstOrFail();
        if ($event->participation_payment_timing !== 'pay_now' || $event->participation_collection_method !== 'bluetick_pay') {
            throw ValidationException::withMessages(['payment_msisdn' => 'Online payment is not enabled for this event.']);
        }
        if (! $event->user()->firstOrFail()->canCollectParticipationPayments()) {
            throw ValidationException::withMessages([
                'payment_msisdn' => "Online payment is temporarily unavailable while the organiser's payment account is reviewed. No charge was initiated.",
            ]);
        }
        if ($guest->rsvp_status !== 'accepted') {
            throw ValidationException::withMessages(['attending_count' => 'Confirm your attendance before making payment.']);
        }
        if ($event->rsvp_confirmation_mode === 'organizer_approval' && $guest->confirmation_status !== 'awaiting_payment') {
            throw ValidationException::withMessages(['payment_msisdn' => 'The organiser must approve this attendance request before payment can begin.']);
        }

        $count = $attendingCount ?? max(1, (int) $guest->attending_count);
        if ($event->rsvp_confirmation_mode === 'organizer_approval' && $count !== (int) $guest->attending_count) {
            throw ValidationException::withMessages(['attending_count' => 'The organiser approved a different party size. Update attendance and request approval again.']);
        }
        $acknowledgement ??= $guest->checkoutAcknowledgements()->latest('id')->first();
        if (! $acknowledgement) {
            throw ValidationException::withMessages(['checkout_acknowledged' => 'Review and accept the event, total amount and cancellation/refund policy before paying.']);
        }
        $this->disclosures->assertCurrent($acknowledgement, $event, $guest, $count);
        $msisdn = $this->snippe->normalizeMsisdn($mobileNumber);
        $network = $this->snippe->networkFor($msisdn);

        $payment = DB::transaction(function () use ($guest, $msisdn, $network, $count, $acknowledgement): ParticipationPayment {
            $lockedGuest = Invitee::whereKey($guest->id)->with('event')->lockForUpdate()->firstOrFail();
            $lockedEvent = $lockedGuest->event()->lockForUpdate()->firstOrFail();
            if (! $lockedEvent->user()->firstOrFail()->canCollectParticipationPayments()) {
                throw ValidationException::withMessages([
                    'payment_msisdn' => "Online payment is temporarily unavailable while the organiser's payment account is reviewed. No charge was initiated.",
                ]);
            }
            $lockedAcknowledgement = CheckoutAcknowledgement::whereKey($acknowledgement->id)->lockForUpdate()->firstOrFail();
            if ($lockedEvent->rsvp_confirmation_mode === 'organizer_approval'
                && $lockedGuest->confirmation_status !== 'awaiting_payment') {
                throw ValidationException::withMessages(['payment_msisdn' => 'The organiser must approve this attendance request before payment can begin.']);
            }
            $this->disclosures->assertCurrent($lockedAcknowledgement, $lockedEvent, $lockedGuest, $count);
            if ($lockedGuest->participationPayments()->where('status', 'paid')->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['payment_msisdn' => 'This attendance payment has already been completed.']);
            }
            $activePayment = $lockedGuest->participationPayments()
                ->whereIn('status', ['initiated', 'pending'])
                ->lockForUpdate()
                ->latest('id')
                ->first();
            if ($activePayment) {
                if ((int) $lockedGuest->attending_count !== $count) {
                    throw ValidationException::withMessages([
                        'attending_count' => 'A payment request is still being processed. Check its status before changing the party size.',
                    ]);
                }
                throw ValidationException::withMessages([
                    'payment_msisdn' => 'A payment request is already being processed. Check its status before sending another prompt.',
                ]);
            }
            $lockedGuest->participationPayments()
                ->whereIn('status', ['failed', 'request_failed'])
                ->update(['status' => 'superseded']);
            if ($count < 1 || $count > max(1, (int) $lockedGuest->max_guests)) {
                throw ValidationException::withMessages(['attending_count' => 'Choose a valid number of attendees.']);
            }
            $lockedGuest->loadMissing('eventPackage');
            $this->pricing->assertAvailable($lockedEvent, $lockedGuest->eventPackage, $count, $lockedGuest);
            if ((int) $lockedGuest->attending_count !== $count) {
                $lockedGuest->update([
                    'attending_count' => $count,
                    'participation_payment_status' => 'payment_required',
                    'confirmation_status' => $lockedEvent->confirmationStatusFor('accepted', 'payment_required', $lockedGuest->confirmation_status),
                    'access_pass_issued_at' => null,
                    'responded_at' => now(),
                ]);
            }

            $quote = $this->pricing->quoteForInvitee(
                $lockedGuest->setRelation('event', $lockedEvent),
                $count,
            );
            $this->assertStorableProviderQuote($quote);

            return $lockedGuest->participationPayments()->create([
                'checkout_acknowledgement_id' => $lockedAcknowledgement->id,
                'reference' => 'OBP'.now()->format('ymd').strtoupper(Str::random(12)),
                'callback_token' => Str::random(32),
                'msisdn' => $msisdn,
                'network' => $network,
                'amount' => $quote['total'],
                'currency' => $quote['currency'],
                'status' => 'initiated',
            ]);
        });

        try {
            $this->snippe->requestPayment($payment);
        } catch (\Throwable $exception) {
            if ($payment->status === 'initiated') {
                $payment->update(['status' => 'request_failed', 'failed_at' => now()]);
            }
            throw $exception;
        }

        $payment->refresh();
        $guest->refresh();
        $paymentStatus = $payment->status === 'paid' ? 'paid' : 'pending';
        $confirmation = $event->confirmationStatusFor($guest->rsvp_status, $paymentStatus, $guest->confirmation_status);
        $guest->update([
            'participation_payment_status' => $paymentStatus,
            'confirmation_status' => $confirmation,
            'access_pass_issued_at' => $paymentStatus === 'paid' && $confirmation === 'confirmed'
                ? ($guest->access_pass_issued_at ?? now())
                : null,
        ]);

        return $payment;
    }

    private function assertStorableProviderQuote(array $quote): void
    {
        $unitPrice = (float) $quote['unit_price'];
        if (strtoupper((string) $quote['currency']) === 'TZS'
            && abs($unitPrice - round($unitPrice)) > 0.000001) {
            throw ValidationException::withMessages([
                'payment_msisdn' => 'Online payment cannot start because this package price includes cents. The organiser must use a whole TZS amount.',
            ]);
        }

        if ((float) $quote['total'] > self::MAX_PAYMENT_AMOUNT) {
            throw ValidationException::withMessages([
                'attending_count' => 'This attendance total exceeds the maximum supported online payment amount. Choose fewer attendees or contact the organiser.',
            ]);
        }
    }
}
