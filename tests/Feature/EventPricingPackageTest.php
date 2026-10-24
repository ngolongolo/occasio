<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventPackage;
use App\Models\Invitee;
use App\Models\User;
use App\Services\CheckoutDisclosure;
use App\Services\EventPricingService;
use App\Services\ParticipationPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class EventPricingPackageTest extends TestCase
{
    use RefreshDatabase;

    private function event(array $attributes = []): Event
    {
        $organiser = User::create([
            'name' => 'Package Host',
            'email' => uniqid('package-host-', true).'@example.com',
            'password' => 'passwordlong',
        ]);
        $event = Event::create(array_merge([
            'user_id' => $organiser->id,
            'title' => 'Package Event',
            'host' => 'Package Host',
            'description' => 'An event with multiple attendance packages.',
            'starts_at' => now()->addMonth(),
            'rsvp_deadline' => now()->addWeeks(3),
            'venue' => 'Dar es Salaam',
            'capacity' => 100,
            'plus_ones_allowed' => true,
            'max_attendees_per_registration' => 5,
            'participation_payment_timing' => 'pay_now',
            'participation_collection_method' => 'bluetick_pay',
            'participation_price' => 10000,
            'participation_currency' => 'TZS',
            'pricing_mode' => 'packages',
            'cancellation_policy' => 'Refunds are available until seven days before the event.',
        ], $attributes));
        $organiser->organiserVerification()->create([
            'organiser_type' => 'professional',
            'verification_level' => 'identity',
            'status' => 'approved',
            'public_name' => $event->host,
            'verified_at' => now(),
            'payout_name_verified_at' => now(),
        ]);

        return $event;
    }

    private function package(Event $event, array $attributes = []): EventPackage
    {
        return $event->packages()->create(array_merge([
            'name' => 'VIP',
            'description' => 'Priority seating and refreshments.',
            'price' => 25000,
            'capacity' => 20,
            'max_per_registration' => 4,
            'sort_order' => 1,
            'is_active' => true,
        ], $attributes));
    }

    private function invitee(Event $event, string $email = 'guest@example.com'): Invitee
    {
        return $event->invitees()->create([
            'name' => 'Package Guest',
            'email' => $email,
            'identity_key' => hash('sha256', $email),
            'token' => str_pad(substr(hash('sha256', $email), 0, 32), 64, 'x'),
            'max_guests' => 5,
            'rsvp_status' => 'accepted',
            'attending_count' => 2,
            'participation_payment_status' => 'payment_required',
            'confirmation_status' => 'awaiting_payment',
        ]);
    }

    public function test_packages_are_ordered_active_and_always_inherit_the_event_currency(): void
    {
        $event = $this->event(['participation_currency' => 'TZS']);
        $later = $this->package($event, ['name' => 'Premium', 'sort_order' => 20]);
        $first = $this->package($event, ['name' => 'General', 'price' => 10000, 'sort_order' => 1]);
        $this->package($event, ['name' => 'Archived', 'sort_order' => 0, 'is_active' => false]);

        $later->forceFill(['currency' => 'USD'])->save();

        $packages = app(EventPricingService::class)->availablePackages($event);
        $this->assertSame([$first->id, $later->id], $packages->pluck('id')->all());
        $this->assertSame('TZS', $later->fresh()->currency);
        $this->assertSame('30000.00', app(EventPricingService::class)->quote($event, 3, $first)['total']);
    }

    public function test_selected_package_quote_is_immutable_and_drives_disclosure_and_payment(): void
    {
        config(['services.snippe.key' => 'snp_test_key', 'services.snippe.webhook_base_url' => null]);
        Http::fake(['api.snippe.sh/v1/payments' => Http::response([
            'status' => 'success',
            'data' => ['reference' => 'snippe-package-1', 'status' => 'pending'],
        ], 201)]);
        $event = $this->event();
        $package = $this->package($event);
        $invitee = $this->invitee($event);
        $pricing = app(EventPricingService::class);

        $pricing->assignPackage($invitee, $package, 2);
        $package->update(['name' => 'VIP New', 'price' => 30000]);

        $quote = $pricing->quoteForInvitee($invitee->fresh(), 2);
        $this->assertSame('VIP', $quote['package_name']);
        $this->assertSame('25000.00', $quote['unit_price']);
        $this->assertSame('50000.00', $quote['total']);

        $acknowledgement = app(CheckoutDisclosure::class)->acknowledge($invitee->fresh(), 2);
        $this->assertSame(CheckoutDisclosure::VERSION, $acknowledgement->version);
        $this->assertSame('VIP', data_get($acknowledgement->snapshot, 'pricing.package.name'));
        $this->assertSame('50000.00', data_get($acknowledgement->snapshot, 'pricing.total'));

        $payment = app(ParticipationPaymentService::class)
            ->initiate($invitee->fresh(), '0656414320', 2, $acknowledgement);
        $this->assertSame('50000.00', $payment->amount);
        Http::assertSent(fn ($request) => data_get($request->data(), 'details.amount') === 50000);
    }

    public function test_package_capacity_and_per_registration_limit_are_enforced(): void
    {
        $event = $this->event();
        $package = $this->package($event, ['capacity' => 2, 'max_per_registration' => 2]);
        $pricing = app(EventPricingService::class);
        $first = $this->invitee($event, 'first@example.com');
        $pricing->assignPackage($first, $package, 2);

        $second = $this->invitee($event, 'second@example.com');
        $second->update(['rsvp_status' => 'pending', 'attending_count' => 0]);
        try {
            $pricing->assignPackage($second, $package, 3);
            $this->fail('Package registration limit was not enforced.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('attending_count', $exception->errors());
        }

        try {
            $pricing->assignPackage($second, $package, 1);
            $this->fail('Package capacity was not enforced.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'Only 0 place(s) remain in the VIP package.',
                $exception->errors()['event_package_id'][0],
            );
        }
    }

    public function test_paid_registration_cannot_switch_package_or_quantity(): void
    {
        $event = $this->event();
        $vip = $this->package($event);
        $general = $this->package($event, ['name' => 'General', 'price' => 10000, 'sort_order' => 0]);
        $invitee = $this->invitee($event);
        $pricing = app(EventPricingService::class);
        $pricing->assignPackage($invitee, $vip, 2);
        $invitee->update(['participation_payment_status' => 'paid']);

        $this->assertSame('50000.00', $pricing->assignPackage($invitee->fresh(), $vip, 2)['total']);

        foreach ([[$general, 2], [$vip, 3]] as [$package, $quantity]) {
            try {
                $pricing->assignPackage($invitee->fresh(), $package, $quantity);
                $this->fail('A paid registration package or quantity was changed.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('event_package_id', $exception->errors());
            }
        }
    }

    public function test_archived_package_is_hidden_from_new_selection_but_keeps_existing_quote(): void
    {
        $event = $this->event();
        $package = $this->package($event);
        $invitee = $this->invitee($event);
        $pricing = app(EventPricingService::class);
        $pricing->assignPackage($invitee, $package, 2);
        $package->update(['is_active' => false]);

        $this->assertCount(0, $pricing->availablePackages($event));
        $this->assertSame('50000.00', $pricing->quoteForInvitee($invitee->fresh(), 2)['total']);

        try {
            $pricing->resolvePackage($event, $package->id);
            $this->fail('An archived package remained available to new registrations.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('event_package_id', $exception->errors());
        }
    }

    public function test_package_change_invalidates_prior_consent_snapshot(): void
    {
        $event = $this->event();
        $vip = $this->package($event);
        $general = $this->package($event, ['name' => 'General', 'price' => 10000]);
        $invitee = $this->invitee($event);
        $pricing = app(EventPricingService::class);
        $disclosures = app(CheckoutDisclosure::class);
        $pricing->assignPackage($invitee, $vip, 2);
        $acknowledgement = $disclosures->acknowledge($invitee->fresh(), 2);

        $pricing->assignPackage($invitee->fresh(), $general, 2);

        $this->expectException(ValidationException::class);
        $disclosures->assertCurrent($acknowledgement, $event->fresh(), $invitee->fresh(), 2);
    }

    public function test_v1_acknowledgement_remains_verifiable_after_the_v2_upgrade(): void
    {
        $event = $this->event();
        $package = $this->package($event);
        $invitee = $this->invitee($event);
        $pricing = app(EventPricingService::class);
        $disclosures = app(CheckoutDisclosure::class);
        $pricing->assignPackage($invitee, $package, 2);
        $invitee->refresh();
        $snapshot = $disclosures->snapshot(
            $event,
            2,
            $invitee->max_guests,
            $invitee,
            CheckoutDisclosure::LEGACY_VERSION,
        );
        $acknowledgement = $invitee->checkoutAcknowledgements()->create([
            'event_id' => $event->id,
            'version' => CheckoutDisclosure::LEGACY_VERSION,
            'snapshot' => $snapshot,
            'snapshot_hash' => $disclosures->hash($snapshot),
            'acknowledged_at' => now(),
        ]);

        $this->assertArrayNotHasKey('package', $snapshot['pricing']);
        $disclosures->assertCurrent($acknowledgement, $event, $invitee, 2);
        $this->assertTrue(true);
    }

    public function test_migration_backfills_existing_paid_events_and_registrations(): void
    {
        $migration = require database_path('migrations/2026_10_23_000025_add_event_pricing_packages.php');
        $migration->down();

        $organiser = User::create([
            'name' => 'Legacy Host',
            'email' => 'legacy-host@example.com',
            'password' => 'passwordlong',
        ]);
        $event = Event::create([
            'user_id' => $organiser->id,
            'title' => 'Legacy Paid Event',
            'host' => 'Legacy Host',
            'description' => 'Created before package pricing.',
            'starts_at' => now()->addMonth(),
            'rsvp_deadline' => now()->addWeeks(3),
            'venue' => 'Arusha',
            'plus_ones_allowed' => true,
            'max_attendees_per_registration' => 3,
            'participation_payment_timing' => 'pay_at_event',
            'participation_price' => 15000,
            'participation_currency' => 'tzs',
        ]);
        $invitee = $event->invitees()->create([
            'name' => 'Legacy Guest',
            'email' => 'legacy-guest@example.com',
            'identity_key' => hash('sha256', 'legacy-guest@example.com'),
            'token' => str_repeat('l', 64),
            'max_guests' => 3,
        ]);

        $migration->up();

        $package = EventPackage::query()->where('event_id', $event->id)->firstOrFail();
        $this->assertSame('General admission', $package->name);
        $this->assertSame('15000.00', $package->price);
        $this->assertSame('TZS', $package->currency);
        $this->assertSame(3, $package->max_per_registration);
        $invitee->refresh();
        $this->assertSame($package->id, $invitee->event_package_id);
        $this->assertSame('General admission', $invitee->package_name_snapshot);
        $this->assertSame('15000.00', $invitee->package_unit_price_snapshot);
        $this->assertSame('TZS', $invitee->package_currency_snapshot);
    }

    public function test_active_payment_blocks_duplicate_prompts_and_party_size_changes(): void
    {
        config(['services.snippe.key' => 'snp_test_key', 'services.snippe.webhook_base_url' => null]);
        Http::fake(['api.snippe.sh/v1/payments' => Http::response([
            'status' => 'success',
            'data' => ['reference' => 'snippe-active-package', 'status' => 'pending'],
        ], 201)]);
        $event = $this->event();
        $package = $this->package($event);
        $invitee = $this->invitee($event);
        app(EventPricingService::class)->assignPackage($invitee, $package, 2);
        $disclosures = app(CheckoutDisclosure::class);
        $payments = app(ParticipationPaymentService::class);
        $acknowledgement = $disclosures->acknowledge($invitee->fresh(), 2);
        $payments->initiate($invitee->fresh(), '0656414320', 2, $acknowledgement);

        try {
            $payments->initiate(
                $invitee->fresh(),
                '0656414320',
                2,
                $disclosures->acknowledge($invitee->fresh(), 2),
            );
            $this->fail('A duplicate payment prompt was created.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('payment_msisdn', $exception->errors());
        }

        try {
            $payments->initiate(
                $invitee->fresh(),
                '0656414320',
                3,
                $disclosures->acknowledge($invitee->fresh(), 3),
            );
            $this->fail('The party size changed while a payment was active.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('attending_count', $exception->errors());
        }

        $this->assertDatabaseCount('participation_payments', 1);
        Http::assertSentCount(1);
    }

    public function test_organiser_can_create_an_invite_only_event_with_multiple_pricing_packages(): void
    {
        $organiser = User::create([
            'name' => 'Private Package Host',
            'email' => 'private-package-host@example.com',
            'password' => 'passwordlong',
        ]);
        $payload = $this->packageEventPayload([
            'host' => 'Private Package Host',
            'visibility' => 'invite_only',
            'participation_collection_method' => 'manual',
            'participation_payment_details' => 'Pay the organiser by mobile money.',
            'payout_payment_type' => null,
            'payout_number' => null,
        ]);

        $response = $this->actingAs($organiser)->post('/events', $payload);
        $event = $organiser->events()->where('title', $payload['title'])->firstOrFail();

        $response->assertRedirect(route('events.show', $event));
        $this->assertSame('packages', $event->pricing_mode);
        $this->assertNull($event->participation_price);
        $this->assertSame(['General', 'VIP'], $event->packages()->pluck('name')->all());
        $this->assertSame(['TZS', 'TZS'], $event->packages()->pluck('currency')->all());
        $this->assertSame([0, 1], $event->packages()->pluck('sort_order')->all());
    }

    public function test_organiser_package_configuration_validation_is_enforced(): void
    {
        $event = $this->event();
        $organiser = $event->user;
        $payload = $this->packageEventPayload();

        $this->actingAs($organiser)->post('/events', array_replace($payload, ['packages' => [
            ['name' => 'VIP', 'price' => 10000, 'is_active' => 1],
            ['name' => 'vip', 'price' => 20000, 'is_active' => 1],
        ]]))->assertSessionHasErrors('packages.1.name');

        $this->actingAs($organiser)->post('/events', array_replace($payload, ['packages' => [
            ['name' => 'Archived', 'price' => 10000, 'is_active' => 0],
        ]]))->assertSessionHasErrors('packages');

        $this->actingAs($organiser)->post('/events', array_replace($payload, ['packages' => [
            ['name' => 'Too small', 'price' => 499, 'is_active' => 1],
        ]]))->assertSessionHasErrors('packages.0.price');

        $this->assertDatabaseMissing('events', ['title' => $payload['title']]);
    }

    public function test_organiser_package_sync_archives_referenced_rows_and_deletes_unused_rows(): void
    {
        $event = $this->event([
            'visibility' => 'invite_only',
            'contact' => '+255784311111',
            'participation_collection_method' => 'manual',
            'participation_payment_details' => 'Pay the organiser directly.',
        ]);
        $general = $this->package($event, ['name' => 'General', 'price' => 10000, 'sort_order' => 0]);
        $vip = $this->package($event, ['name' => 'VIP', 'price' => 25000, 'sort_order' => 1]);
        $unused = $this->package($event, ['name' => 'Unused', 'price' => 40000, 'sort_order' => 2]);
        $invitee = $this->invitee($event);
        app(EventPricingService::class)->assignPackage($invitee, $general, 2);

        $response = $this->actingAs($event->user)->put(route('events.design.update', $event), [
            'primary_color' => '#56283f',
            'background_color' => '#fcf9f4',
            'text_color' => '#30252a',
            'sms_template' => 'Hello {guest_name}. RSVP: {rsvp_url}',
            'contact' => '+255784311111',
            'participation_payment_timing' => 'pay_now',
            'participation_collection_method' => 'manual',
            'pricing_mode' => 'packages',
            'participation_currency' => 'TZS',
            'participation_payment_details' => 'Pay the organiser directly.',
            'cancellation_policy' => $event->cancellation_policy,
            'packages' => [[
                'id' => $vip->id,
                'name' => 'VIP Plus',
                'description' => 'Priority seating and refreshments.',
                'price' => 30000,
                'capacity' => 25,
                'max_per_registration' => 3,
                'is_active' => 1,
            ]],
        ]);

        $response->assertRedirect(route('events.design', $event));
        $this->assertFalse($general->fresh()->is_active);
        $this->assertDatabaseMissing('event_packages', ['id' => $unused->id]);
        $this->assertDatabaseHas('event_packages', [
            'id' => $vip->id,
            'name' => 'VIP Plus',
            'price' => 30000,
            'sort_order' => 0,
            'is_active' => true,
        ]);
        $this->assertSame('General', $invitee->fresh()->package_name_snapshot);
        $this->assertSame('10000.00', $invitee->package_unit_price_snapshot);
    }

    public function test_organiser_cannot_reduce_package_capacity_below_reserved_admissions(): void
    {
        $event = $this->event([
            'visibility' => 'invite_only',
            'contact' => '+255784311111',
            'participation_collection_method' => 'manual',
            'participation_payment_details' => 'Pay the organiser directly.',
        ]);
        $package = $this->package($event, ['capacity' => 10, 'max_per_registration' => 5]);
        $pricing = app(EventPricingService::class);
        $first = $this->invitee($event, 'capacity-one@example.com');
        $pricing->assignPackage($first, $package, 2);
        $second = $this->invitee($event, 'capacity-two@example.com');
        $second->update(['attending_count' => 3]);
        $pricing->assignPackage($second, $package, 3);

        $response = $this->actingAs($event->user)->put(
            route('events.design.update', $event),
            $this->packageDesignPayload($event, $package, ['capacity' => 4]),
        );

        $response->assertSessionHasErrors('packages.0.capacity');
        $this->assertSame(10, $package->fresh()->capacity);
        $this->assertStringContainsString(
            'already reserves 5 admission',
            $response->getSession()->get('errors')->first('packages.0.capacity'),
        );
    }

    public function test_organiser_cannot_reduce_package_registration_limit_below_largest_reserved_rsvp(): void
    {
        $event = $this->event([
            'visibility' => 'invite_only',
            'contact' => '+255784311111',
            'participation_collection_method' => 'manual',
            'participation_payment_details' => 'Pay the organiser directly.',
        ]);
        $package = $this->package($event, ['capacity' => 10, 'max_per_registration' => 5]);
        $pricing = app(EventPricingService::class);
        $reserved = $this->invitee($event, 'largest-party@example.com');
        $reserved->update(['attending_count' => 3]);
        $pricing->assignPackage($reserved, $package, 3);
        $rejected = $this->invitee($event, 'rejected-party@example.com');
        $rejected->update(['attending_count' => 5]);
        $pricing->assignPackage($rejected, $package, 5);
        $rejected->update(['confirmation_status' => 'rejected']);

        $response = $this->actingAs($event->user)->put(
            route('events.design.update', $event),
            $this->packageDesignPayload($event, $package, ['max_per_registration' => 2]),
        );

        $response->assertSessionHasErrors('packages.0.max_per_registration');
        $this->assertSame(5, $package->fresh()->max_per_registration);
        $this->assertStringContainsString(
            'existing RSVP reserves 3 attendee',
            $response->getSession()->get('errors')->first('packages.0.max_per_registration'),
        );

        $this->actingAs($event->user)->put(
            route('events.design.update', $event),
            $this->packageDesignPayload($event, $package, ['capacity' => 3, 'max_per_registration' => 3]),
        )->assertRedirect(route('events.design', $event))->assertSessionHasNoErrors();

        $this->assertSame(3, $package->fresh()->capacity);
        $this->assertSame(3, $package->fresh()->max_per_registration);
    }

    public function test_manual_payment_summary_and_export_use_the_immutable_package_quote(): void
    {
        $event = $this->event([
            'visibility' => 'invite_only',
            'participation_collection_method' => 'manual',
            'participation_payment_details' => 'Pay the organiser directly.',
        ]);
        $package = $this->package($event, ['name' => 'VIP', 'price' => 25000]);
        $invitee = $this->invitee($event);
        app(EventPricingService::class)->assignPackage($invitee, $package, 2);
        $invitee->update(['participation_payment_status' => 'paid']);
        $package->update(['name' => 'VIP New', 'price' => 30000]);

        $this->actingAs($event->user)->get(route('events.show', $event))
            ->assertOk()
            ->assertViewHas('paymentSummary', fn ($summary) => (float) $summary['collected'] === 50000.0);

        $csv = $this->actingAs($event->user)->get(route('events.export', $event));
        $csv->assertOk();
        $content = $csv->streamedContent();
        $this->assertStringContainsString('participation_package', $content);
        $this->assertStringContainsString('VIP', $content);
        $this->assertStringContainsString('50000', $content);
    }

    public function test_rejected_organiser_confirmation_releases_global_and_package_capacity(): void
    {
        $event = $this->event([
            'capacity' => 6,
            'rsvp_confirmation_mode' => 'organizer_approval',
        ]);
        $package = $this->package($event, ['capacity' => 6, 'max_per_registration' => 5]);
        $pricing = app(EventPricingService::class);

        $confirmed = $this->invitee($event, 'confirmed-capacity@example.com');
        $pricing->assignPackage($confirmed, $package, 2);
        $confirmed->update(['confirmation_status' => 'confirmed']);

        $pending = $this->invitee($event, 'pending-capacity@example.com');
        $pricing->assignPackage($pending, $package, 2);
        $pending->update(['confirmation_status' => 'awaiting_approval']);

        $rejected = $this->invitee($event, 'rejected-capacity@example.com');
        $pricing->assignPackage($rejected, $package, 2);
        $rejected->update(['confirmation_status' => 'awaiting_approval']);

        $this->actingAs($event->user)->put(
            route('events.rsvp-confirmations.update', [$event, $rejected]),
            ['confirmation_status' => 'rejected'],
        )->assertRedirect()->assertSessionHasNoErrors();

        $this->assertTrue($confirmed->fresh()->reservesCapacity());
        $this->assertTrue($pending->fresh()->reservesCapacity());
        $this->assertFalse($rejected->fresh()->reservesCapacity());
        $this->assertSame(2, $event->fresh()->remainingCapacity());
        $this->assertSame(2, $package->fresh()->remainingCapacity());

        $replacement = $this->invitee($event, 'replacement-capacity@example.com');
        $pricing->assignPackage($replacement, $package->fresh(), 2);
        $replacement->update(['confirmation_status' => 'awaiting_approval']);

        $this->assertSame(0, $event->fresh()->remainingCapacity());
        $this->assertSame(0, $package->fresh()->remainingCapacity());
    }

    public function test_rejected_confirmation_does_not_block_a_free_public_registration(): void
    {
        $event = $this->event([
            'capacity' => 1,
            'visibility' => 'link',
            'plus_ones_allowed' => false,
            'max_attendees_per_registration' => 1,
            'rsvp_confirmation_mode' => 'organizer_approval',
            'participation_payment_timing' => 'free',
            'pricing_mode' => 'single',
            'participation_price' => null,
        ]);
        $rejected = $this->invitee($event, 'rejected-free@example.com');
        $rejected->update([
            'attending_count' => 1,
            'max_guests' => 1,
            'participation_payment_status' => 'not_required',
            'confirmation_status' => 'rejected',
        ]);

        $this->assertSame(1, $event->fresh()->remainingCapacity());

        $this->post($event->registrationUrl(), [
            'name' => 'Replacement Guest',
            'email' => 'replacement-free@example.com',
            'rsvp_status' => 'accepted',
            'attending_count' => 1,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $replacement = $event->invitees()->where('email', 'replacement-free@example.com')->firstOrFail();
        $this->assertSame('awaiting_approval', $replacement->confirmation_status);
        $this->assertTrue($replacement->reservesCapacity());
        $this->assertSame(0, $event->fresh()->remainingCapacity());
    }

    public function test_reapproving_a_rejected_registration_rechecks_global_capacity(): void
    {
        $event = $this->event([
            'capacity' => 2,
            'rsvp_confirmation_mode' => 'organizer_approval',
        ]);
        $package = $this->package($event, ['capacity' => 10]);
        $pricing = app(EventPricingService::class);

        $rejected = $this->invitee($event, 'reapprove-rejected@example.com');
        $rejected->update(['attending_count' => 1, 'confirmation_status' => 'rejected']);
        $pricing->assignPackage($rejected, $package, 1);

        $occupant = $this->invitee($event, 'reapprove-occupant@example.com');
        $pricing->assignPackage($occupant, $package, 2);
        $occupant->update(['confirmation_status' => 'confirmed']);

        $this->actingAs($event->user)->put(
            route('events.rsvp-confirmations.update', [$event, $rejected]),
            ['confirmation_status' => 'confirmed'],
        )->assertSessionHasErrors('attending_count');
        $this->assertSame('rejected', $rejected->fresh()->confirmation_status);

        $occupant->update(['confirmation_status' => 'rejected']);
        $this->actingAs($event->user)->put(
            route('events.rsvp-confirmations.update', [$event, $rejected]),
            ['confirmation_status' => 'confirmed'],
        )->assertRedirect()->assertSessionHasNoErrors();

        $rejected->refresh();
        $this->assertSame('awaiting_payment', $rejected->confirmation_status);
        $this->assertTrue($rejected->reservesCapacity());
    }

    public function test_manual_payment_cannot_restore_a_rejected_registration_into_a_full_package(): void
    {
        $event = $this->event([
            'capacity' => 10,
            'participation_collection_method' => 'manual',
            'participation_payment_details' => 'Pay the organiser directly.',
            'rsvp_confirmation_mode' => 'automatic',
        ]);
        $package = $this->package($event, ['capacity' => 2]);
        $pricing = app(EventPricingService::class);

        $rejected = $this->invitee($event, 'manual-rejected@example.com');
        $rejected->update([
            'attending_count' => 1,
            'participation_payment_status' => 'payment_reported',
            'confirmation_status' => 'rejected',
        ]);
        $pricing->assignPackage($rejected, $package, 1);

        $occupant = $this->invitee($event, 'manual-occupant@example.com');
        $pricing->assignPackage($occupant, $package, 2);
        $occupant->update(['confirmation_status' => 'confirmed']);

        $this->actingAs($event->user)->put(
            route('events.participation-payments.update', [$event, $rejected]),
            ['status' => 'paid'],
        )->assertSessionHasErrors('event_package_id');
        $rejected->refresh();
        $this->assertSame('payment_reported', $rejected->participation_payment_status);
        $this->assertSame('rejected', $rejected->confirmation_status);

        $occupant->update(['confirmation_status' => 'rejected']);
        $this->actingAs($event->user)->put(
            route('events.participation-payments.update', [$event, $rejected]),
            ['status' => 'paid'],
        )->assertRedirect()->assertSessionHasNoErrors();

        $rejected->refresh();
        $this->assertSame('paid', $rejected->participation_payment_status);
        $this->assertSame('confirmed', $rejected->confirmation_status);
        $this->assertTrue($rejected->reservesCapacity());
    }

    public function test_online_configuration_requires_whole_tzs_prices_while_offline_collection_keeps_decimal_prices(): void
    {
        $event = $this->event();
        $organiser = $event->user;
        $onlinePayload = $this->packageEventPayload([
            'title' => 'Fractional Online Package Event',
            'packages' => [[
                'name' => 'General',
                'price' => '10000.50',
                'capacity' => 20,
                'max_per_registration' => 2,
                'is_active' => 1,
            ]],
        ]);

        $packageResponse = $this->actingAs($organiser)->post('/events', $onlinePayload);
        $packageResponse->assertSessionHasErrors('packages.0.price');
        $this->assertStringContainsString(
            'whole TZS amounts',
            $packageResponse->getSession()->get('errors')->first('packages.0.price'),
        );

        $singleResponse = $this->actingAs($organiser)->post('/events', array_replace($onlinePayload, [
            'title' => 'Fractional Online Single Price Event',
            'pricing_mode' => 'single',
            'participation_price' => '10000.50',
            'packages' => null,
        ]));
        $singleResponse->assertSessionHasErrors('participation_price');
        $this->assertStringContainsString(
            'whole TZS amounts',
            $singleResponse->getSession()->get('errors')->first('participation_price'),
        );

        $offlinePayload = array_replace($onlinePayload, [
            'title' => 'Fractional Manual Package Event',
            'visibility' => 'invite_only',
            'participation_collection_method' => 'manual',
            'participation_payment_details' => 'Pay the organiser directly.',
            'payout_payment_type' => null,
            'payout_number' => null,
        ]);
        $offlineResponse = $this->actingAs($organiser)->post('/events', $offlinePayload);
        $offlineEvent = $organiser->events()->where('title', $offlinePayload['title'])->firstOrFail();
        $offlineResponse->assertRedirect(route('events.show', $offlineEvent))->assertSessionHasNoErrors();
        $this->assertSame('10000.50', $offlineEvent->packages()->firstOrFail()->price);

        $payAtEventPayload = array_replace($onlinePayload, [
            'title' => 'Fractional Pay At Event',
            'participation_payment_timing' => 'pay_at_event',
            'pricing_mode' => 'single',
            'participation_price' => '12500.75',
            'packages' => null,
            'payout_payment_type' => null,
            'payout_number' => null,
        ]);
        $payAtEventResponse = $this->actingAs($organiser)->post('/events', $payAtEventPayload);
        $payAtEvent = $organiser->events()->where('title', $payAtEventPayload['title'])->firstOrFail();
        $payAtEventResponse->assertRedirect(route('events.show', $payAtEvent))->assertSessionHasNoErrors();
        $this->assertSame('12500.75', $payAtEvent->participation_price);
    }

    public function test_online_configuration_rejects_single_and_package_totals_that_exceed_the_payment_column(): void
    {
        $event = $this->event();
        $organiser = $event->user;
        $payload = $this->packageEventPayload([
            'title' => 'Oversized Package Total',
            'plus_ones_allowed' => 1,
            'max_attendees_per_registration' => 2,
            'packages' => [[
                'name' => 'Large package',
                'price' => '500000000000',
                'capacity' => 20,
                'max_per_registration' => 2,
                'is_active' => 1,
            ]],
        ]);

        $packageResponse = $this->actingAs($organiser)->post('/events', $payload);
        $packageResponse->assertSessionHasErrors('packages.0.price');
        $this->assertStringContainsString(
            'maximum supported online payment total',
            $packageResponse->getSession()->get('errors')->first('packages.0.price'),
        );

        $singleResponse = $this->actingAs($organiser)->post('/events', array_replace($payload, [
            'title' => 'Oversized Single Price Total',
            'pricing_mode' => 'single',
            'participation_price' => '500000000000',
            'packages' => null,
        ]));
        $singleResponse->assertSessionHasErrors('participation_price');
        $this->assertStringContainsString(
            'maximum supported online payment total',
            $singleResponse->getSession()->get('errors')->first('participation_price'),
        );
    }

    public function test_online_package_update_revalidates_whole_tzs_prices(): void
    {
        $event = $this->event([
            'contact' => '+255784311111',
            'payout_payment_type' => 'MixxByYas',
            'payout_number' => '0656414320',
        ]);
        $package = $this->package($event);

        $response = $this->actingAs($event->user)->put(route('events.design.update', $event), [
            'primary_color' => '#56283f',
            'background_color' => '#fcf9f4',
            'text_color' => '#30252a',
            'sms_template' => 'Hello {guest_name}. RSVP: {rsvp_url}',
            'contact' => $event->contact,
            'participation_payment_timing' => 'pay_now',
            'participation_collection_method' => 'bluetick_pay',
            'pricing_mode' => 'packages',
            'participation_currency' => 'TZS',
            'payout_payment_type' => 'MixxByYas',
            'payout_number' => '0656414320',
            'cancellation_policy' => $event->cancellation_policy,
            'packages' => [[
                'id' => $package->id,
                'name' => $package->name,
                'description' => $package->description,
                'price' => '25000.50',
                'capacity' => $package->capacity,
                'max_per_registration' => $package->max_per_registration,
                'is_active' => 1,
            ]],
        ]);

        $response->assertSessionHasErrors('packages.0.price');
        $this->assertSame('25000.00', $package->fresh()->price);
    }

    public function test_payment_service_rejects_a_fractional_tzs_snapshot_before_creating_a_payment(): void
    {
        $event = $this->event();
        $package = $this->package($event, ['price' => '25000.50']);
        $invitee = $this->invitee($event);
        app(EventPricingService::class)->assignPackage($invitee, $package, 1);
        $acknowledgement = app(CheckoutDisclosure::class)->acknowledge($invitee->fresh(), 1);

        try {
            app(ParticipationPaymentService::class)->initiate($invitee->fresh(), '0656414320', 1, $acknowledgement);
            $this->fail('A fractional TZS payment was created.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('payment_msisdn', $exception->errors());
            $this->assertStringContainsString('whole TZS amount', $exception->errors()['payment_msisdn'][0]);
        }

        $this->assertDatabaseCount('participation_payments', 0);
    }

    public function test_payment_service_rejects_an_unstorable_multiplied_total_before_insert(): void
    {
        $event = $this->event();
        $package = $this->package($event, ['price' => '600000000000', 'max_per_registration' => 2]);
        $invitee = $this->invitee($event);
        app(EventPricingService::class)->assignPackage($invitee, $package, 2);
        $acknowledgement = app(CheckoutDisclosure::class)->acknowledge($invitee->fresh(), 2);

        try {
            app(ParticipationPaymentService::class)->initiate($invitee->fresh(), '0656414320', 2, $acknowledgement);
            $this->fail('An unstorable participation payment was created.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('attending_count', $exception->errors());
            $this->assertStringContainsString('maximum supported online payment amount', $exception->errors()['attending_count'][0]);
        }

        $this->assertDatabaseCount('participation_payments', 0);
    }

    private function packageEventPayload(array $overrides = []): array
    {
        return array_replace([
            'event_type' => 'event',
            'title' => 'Configured Package Event',
            'host' => 'Package Host',
            'description' => 'A paid event with selectable attendance packages.',
            'starts_at' => now()->addMonth()->format('Y-m-d H:i:s'),
            'rsvp_deadline' => now()->addWeeks(3)->format('Y-m-d'),
            'venue' => 'Dar es Salaam',
            'contact' => '+255784311111',
            'visibility' => 'link',
            'participation_payment_timing' => 'pay_now',
            'participation_collection_method' => 'bluetick_pay',
            'pricing_mode' => 'packages',
            'participation_currency' => 'TZS',
            'payout_payment_type' => 'MixxByYas',
            'payout_number' => '0656414320',
            'cancellation_policy' => 'Refunds are available until 48 hours before the event.',
            'packages' => [
                ['name' => 'General', 'description' => 'Standard entry.', 'price' => 10000, 'capacity' => 60, 'max_per_registration' => 4, 'is_active' => 1],
                ['name' => 'VIP', 'description' => 'Priority seating.', 'price' => 25000, 'capacity' => 20, 'max_per_registration' => 2, 'is_active' => 1],
            ],
        ], $overrides);
    }

    private function packageDesignPayload(Event $event, EventPackage $package, array $packageOverrides = []): array
    {
        return [
            'primary_color' => '#56283f',
            'background_color' => '#fcf9f4',
            'text_color' => '#30252a',
            'sms_template' => 'Hello {guest_name}. RSVP: {rsvp_url}',
            'contact' => $event->contact,
            'participation_payment_timing' => 'pay_now',
            'participation_collection_method' => 'manual',
            'pricing_mode' => 'packages',
            'participation_currency' => 'TZS',
            'participation_payment_details' => $event->participation_payment_details,
            'cancellation_policy' => $event->cancellation_policy,
            'packages' => [array_replace([
                'id' => $package->id,
                'name' => $package->name,
                'description' => $package->description,
                'price' => $package->price,
                'capacity' => $package->capacity,
                'max_per_registration' => $package->max_per_registration,
                'is_active' => $package->is_active,
            ], $packageOverrides)],
        ];
    }
}
