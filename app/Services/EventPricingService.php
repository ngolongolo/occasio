<?php

namespace App\Services;

use App\Models\Event;
use App\Models\EventPackage;
use App\Models\Invitee;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EventPricingService
{
    public const MODE_SINGLE = 'single';
    public const MODE_PACKAGES = 'packages';

    public function availablePackages(Event $event): Collection
    {
        return $event->activePackages()->get();
    }

    public function resolvePackage(Event $event, mixed $packageId): ?EventPackage
    {
        if (! $event->usesPackages()) return null;

        if (filter_var($packageId, FILTER_VALIDATE_INT) === false || (int) $packageId < 1) {
            throw ValidationException::withMessages([
                'event_package_id' => 'Choose an attendance package.',
            ]);
        }

        $package = $event->packages()->whereKey((int) $packageId)->first();
        if (! $package || ! $package->is_active) {
            throw ValidationException::withMessages([
                'event_package_id' => 'That attendance package is no longer available. Choose another package.',
            ]);
        }

        return $package;
    }

    /**
     * Return a normalized per-person quote. Currency always comes from the event,
     * even if an old package row contains a different value.
     */
    public function quote(Event $event, int $quantity, ?EventPackage $package = null): array
    {
        $this->assertPositiveQuantity($quantity);

        if ($event->usesPackages()) {
            if (! $package || (int) $package->event_id !== (int) $event->id || ! $package->is_active) {
                throw ValidationException::withMessages([
                    'event_package_id' => 'Choose an available attendance package.',
                ]);
            }

            return $this->makeQuote(
                $event,
                $quantity,
                (string) $package->price,
                $package->id,
                $package->name,
                $package->description,
                'package',
            );
        }

        return $this->makeQuote(
            $event,
            $quantity,
            (string) ($event->participation_price ?? 0),
            null,
            'General admission',
            null,
            'event',
        );
    }

    /**
     * Use the invitee's immutable unit-price snapshot whenever it exists. This
     * keeps an accepted quote stable if an organiser later edits or archives a
     * package, while old registrations still fall back to current event pricing.
     */
    public function quoteForInvitee(Invitee $invitee, ?int $quantity = null): array
    {
        $event = $invitee->relationLoaded('event')
            ? $invitee->event
            : $invitee->event()->firstOrFail();
        $quantity ??= max(1, (int) $invitee->attending_count);
        $this->assertPositiveQuantity($quantity);

        if ($invitee->hasPackageQuoteSnapshot()) {
            return $this->makeQuote(
                $event,
                $quantity,
                (string) $invitee->package_unit_price_snapshot,
                $invitee->event_package_id ? (int) $invitee->event_package_id : null,
                $invitee->package_name_snapshot ?: 'General admission',
                null,
                'snapshot',
                $invitee->package_currency_snapshot,
            );
        }

        $package = $invitee->relationLoaded('eventPackage')
            ? $invitee->eventPackage
            : $invitee->eventPackage()->first();

        return $this->quote($event, $quantity, $package);
    }

    /**
     * Select a package and freeze its name, unit price and event currency on the
     * registration. Re-selecting the same package preserves the original quote.
     */
    public function assignPackage(Invitee $invitee, ?EventPackage $package, int $quantity): array
    {
        if (! $invitee->exists) {
            throw new \LogicException('Save the invitee before assigning an attendance package.');
        }

        $quote = DB::transaction(function () use ($invitee, $package, $quantity): array {
            $lockedInvitee = Invitee::query()->lockForUpdate()->findOrFail($invitee->id);
            $event = Event::query()->lockForUpdate()->findOrFail($lockedInvitee->event_id);
            $lockedPackage = null;

            if ($event->usesPackages()) {
                if (! $package || (int) $package->event_id !== (int) $event->id) {
                    throw ValidationException::withMessages([
                        'event_package_id' => 'Choose an attendance package.',
                    ]);
                }

                $lockedPackage = EventPackage::query()
                    ->where('event_id', $event->id)
                    ->lockForUpdate()
                    ->find($package->id);
                $alreadySelected = $lockedPackage
                    && (int) $lockedInvitee->event_package_id === (int) $lockedPackage->id
                    && $lockedInvitee->hasPackageQuoteSnapshot();
                if (! $lockedPackage || (! $lockedPackage->is_active && ! $alreadySelected)) {
                    throw ValidationException::withMessages([
                        'event_package_id' => 'That attendance package is no longer available. Choose another package.',
                    ]);
                }
            }

            $sameSelection = $event->usesPackages()
                ? $lockedPackage && (int) $lockedInvitee->event_package_id === (int) $lockedPackage->id
                : true;
            $hasExistingQuote = $sameSelection && $lockedInvitee->hasPackageQuoteSnapshot();
            $requestedQuote = $hasExistingQuote
                ? $this->quoteForInvitee($lockedInvitee->setRelation('event', $event), $quantity)
                : $this->quote($event, $quantity, $lockedPackage);

            if ($lockedInvitee->participation_payment_status === 'paid') {
                $storedQuote = $this->quoteForInvitee($lockedInvitee->setRelation('event', $event));
                $selectionChanged = ! $sameSelection
                    || (int) $lockedInvitee->attending_count !== $quantity
                    || $storedQuote['unit_price'] !== $requestedQuote['unit_price']
                    || $storedQuote['currency'] !== $requestedQuote['currency'];
                if ($selectionChanged) {
                    throw ValidationException::withMessages([
                        'event_package_id' => 'This paid registration is already confirmed. Contact the organiser to change its package or party size.',
                    ]);
                }

                return $storedQuote;
            }

            $this->assertAvailable($event, $lockedPackage, $quantity, $lockedInvitee);

            if (! $hasExistingQuote) {
                $lockedInvitee->forceFill([
                    'event_package_id' => $lockedPackage?->id,
                    'package_name_snapshot' => $requestedQuote['package_name'],
                    'package_unit_price_snapshot' => $requestedQuote['unit_price'],
                    'package_currency_snapshot' => $requestedQuote['currency'],
                ])->save();
            }

            return $requestedQuote;
        });

        $invitee->refresh();

        return $quote;
    }

    public function assertAvailable(Event $event, ?EventPackage $package, int $quantity, ?Invitee $except = null): void
    {
        $this->assertPositiveQuantity($quantity);

        // Invite-only registrations may have a per-invitation allowance that is
        // intentionally different from the public registration limit.
        $partyLimit = $except?->max_guests
            ? max(1, (int) $except->max_guests)
            : $event->registrationPartyLimit();

        if ($event->usesPackages()) {
            if (! $package || (int) $package->event_id !== (int) $event->id) {
                throw ValidationException::withMessages(['event_package_id' => 'Choose an attendance package.']);
            }
            $alreadySelected = $except
                && (int) $except->event_package_id === (int) $package->id
                && $except->hasPackageQuoteSnapshot();
            if (! $package->is_active && ! $alreadySelected) {
                throw ValidationException::withMessages([
                    'event_package_id' => 'That attendance package is no longer available. Choose another package.',
                ]);
            }
            if ($package->max_per_registration !== null) {
                $partyLimit = min($partyLimit, max(1, (int) $package->max_per_registration));
            }
        }

        if ($quantity > $partyLimit) {
            throw ValidationException::withMessages([
                'attending_count' => 'This package allows up to '.$partyLimit.' attendee(s) per registration.',
            ]);
        }

        if ($event->capacity !== null) {
            $reservedByOthers = (int) $event->invitees()
                ->reservingCapacity()
                ->when($except?->exists, fn ($query) => $query->whereKeyNot($except->id))
                ->sum('attending_count');
            if ($reservedByOthers + $quantity > (int) $event->capacity) {
                throw ValidationException::withMessages([
                    'attending_count' => 'Only '.max(0, (int) $event->capacity - $reservedByOthers).' place(s) remain for this event.',
                ]);
            }
        }

        if ($package?->capacity !== null) {
            $remaining = $package->remainingCapacity($except);
            if ($remaining !== null && $quantity > $remaining) {
                throw ValidationException::withMessages([
                    'event_package_id' => 'Only '.$remaining.' place(s) remain in the '.$package->name.' package.',
                ]);
            }
        }
    }

    private function assertPositiveQuantity(int $quantity): void
    {
        if ($quantity < 1) {
            throw ValidationException::withMessages([
                'attending_count' => 'Choose at least one attendee.',
            ]);
        }
    }

    private function makeQuote(
        Event $event,
        int $quantity,
        string $unitPrice,
        ?int $packageId,
        string $packageName,
        ?string $packageDescription,
        string $source,
        ?string $snapshotCurrency = null,
    ): array {
        $currency = strtoupper((string) ($snapshotCurrency ?: $event->participation_currency ?: 'TZS'));
        $unitMinor = (int) round((float) $unitPrice * 100);
        $totalMinor = $unitMinor * $quantity;

        return [
            'package_id' => $packageId,
            'package_name' => $packageName,
            'package_description' => $packageDescription,
            'unit_price' => number_format($unitMinor / 100, 2, '.', ''),
            'currency' => $currency,
            'quantity' => $quantity,
            'subtotal' => number_format($totalMinor / 100, 2, '.', ''),
            'total' => number_format($totalMinor / 100, 2, '.', ''),
            'source' => $source,
        ];
    }
}
