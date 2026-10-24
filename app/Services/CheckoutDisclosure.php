<?php

namespace App\Services;

use App\Models\CheckoutAcknowledgement;
use App\Models\Event;
use App\Models\Invitee;
use Illuminate\Validation\ValidationException;

class CheckoutDisclosure
{
    public const VERSION = '2026-10-23.v2';

    public const LEGACY_VERSION = '2026-10-23.v1';

    public const ACKNOWLEDGEMENT = 'I have reviewed the event, organiser, total amount and cancellation/refund policy.';

    public function __construct(private readonly EventPricingService $pricing) {}

    public function snapshot(
        Event $event,
        int $attendingCount,
        ?int $maximumAllowed = null,
        ?Invitee $invitee = null,
        string $version = self::VERSION,
    ): array
    {
        $event->loadMissing('user.organiserVerification');
        $organiser = $event->user->organiserVerificationSummary();
        $quote = $invitee
            ? $this->pricing->quoteForInvitee($invitee->setRelation('event', $event), $attendingCount)
            : $this->pricing->quote($event, $attendingCount);

        $pricing = [
            'currency' => $quote['currency'],
            'unit_price' => $quote['unit_price'],
            'quantity' => $attendingCount,
            'subtotal' => $quote['subtotal'],
            'okesheni_fee' => number_format(0, 2, '.', ''),
            'total' => $quote['total'],
            'additional_fee_notice' => 'Any mobile-money provider charges are separate unless shown in the total.',
        ];
        if ($version === self::VERSION) {
            $pricing['package'] = [
                'id' => $quote['package_id'],
                'name' => $quote['package_name'],
                'description' => $quote['package_description'],
            ];
        }

        return [
            'version' => $version,
            'event' => [
                'id' => $event->id,
                'public_code' => $event->public_code,
                'title' => $event->title,
                'starts_at' => $event->starts_at?->toIso8601String(),
                'venue' => $event->venue,
                'updated_at' => $event->updated_at?->toIso8601String(),
            ],
            'organiser' => [
                'display_name' => $organiser['public_name'] ?: $event->host,
                'organiser_type' => $organiser['organiser_type'],
                'verification_level' => $organiser['verification_level'],
                'verification_status' => $organiser['verification_status'],
                'badge_label' => $organiser['badge_label'],
                'verified_at' => $organiser['verified_at'],
                'contact_email' => $organiser['public_contact_email'],
                'contact_phone' => $organiser['public_contact_phone'] ?: $event->contact,
            ],
            'attendance' => [
                'count' => $attendingCount,
                'maximum_allowed' => max(1, $maximumAllowed ?? (int) $event->registrationPartyLimit()),
            ],
            'pricing' => $pricing,
            'payment' => [
                'timing' => $event->participation_payment_timing,
                'collection_method' => $event->participation_collection_method,
                'recipient' => $event->host,
                'manual_payment_details' => $event->participation_collection_method === 'manual'
                    ? $event->participation_payment_details
                    : null,
            ],
            'cancellation_policy' => filled($event->cancellation_policy)
                ? $event->cancellation_policy
                : 'No cancellation or refund policy was provided. Contact the organiser before committing to payment.',
            'access_pass_condition' => $event->participation_payment_timing === 'pay_now'
                ? 'The QR access pass is issued only after payment and any required organiser approval are confirmed.'
                : 'The QR access pass is issued after the attendance confirmation requirements are complete.',
            'acknowledgement_text' => self::ACKNOWLEDGEMENT,
        ];
    }

    public function acknowledge(Invitee $invitee, int $attendingCount): CheckoutAcknowledgement
    {
        $snapshot = $this->snapshot(
            $invitee->event()->firstOrFail(),
            $attendingCount,
            (int) $invitee->max_guests,
            $invitee,
        );

        return $invitee->checkoutAcknowledgements()->create([
            'event_id' => $invitee->event_id,
            'version' => self::VERSION,
            'snapshot' => $snapshot,
            'snapshot_hash' => $this->hash($snapshot),
            'acknowledged_at' => now(),
        ]);
    }

    public function assertCurrent(CheckoutAcknowledgement $acknowledgement, Event $event, Invitee $invitee, int $attendingCount): void
    {
        $storedSnapshot = (array) $acknowledgement->snapshot;
        $version = (string) ($acknowledgement->version ?: self::LEGACY_VERSION);
        $currentSnapshot = $this->snapshot(
            $event,
            $attendingCount,
            (int) $invitee->max_guests,
            $invitee,
            $version,
        );
        $valid = (int) $acknowledgement->event_id === (int) $event->id
            && (int) $acknowledgement->invitee_id === (int) $invitee->id
            && hash_equals((string) $acknowledgement->snapshot_hash, $this->hash($storedSnapshot))
            && hash_equals($this->hash($storedSnapshot), $this->hash($currentSnapshot));

        if (! $valid) {
            throw ValidationException::withMessages([
                'checkout_acknowledged' => 'The event or payment details changed. Review the latest total and policy, then confirm again.',
            ]);
        }
    }

    public function hash(array $snapshot): string
    {
        return hash('sha256', json_encode($this->canonicalize($snapshot), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) return $value;
        if (array_is_list($value)) return array_map(fn ($item) => $this->canonicalize($item), $value);

        ksort($value);
        foreach ($value as $key => $item) $value[$key] = $this->canonicalize($item);

        return $value;
    }
}
