<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventPackage;
use App\Models\Invitee;
use App\Models\ParticipationPayment;
use App\Models\User;
use App\Services\EventPricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EventPackageGuestFlowTest extends TestCase
{
    use RefreshDatabase;

    private function event(array $attributes = []): Event
    {
        $organiser = User::create([
            'name' => 'Package Host',
            'email' => uniqid('guest-package-host-', true).'@example.com',
            'password' => 'passwordlong',
        ]);

        return Event::create(array_merge([
            'user_id' => $organiser->id,
            'event_type' => 'event',
            'title' => 'Package Experience',
            'host' => 'Package Host',
            'description' => 'Choose the experience that suits you.',
            'starts_at' => now()->addMonth(),
            'rsvp_deadline' => now()->addWeeks(3),
            'venue' => 'Dar es Salaam',
            'capacity' => 20,
            'visibility' => 'link',
            'plus_ones_allowed' => true,
            'max_attendees_per_registration' => 4,
            'participation_enabled' => true,
            'participation_payment_timing' => 'pay_at_event',
            'participation_collection_method' => 'manual',
            'participation_price' => 10000,
            'participation_currency' => 'TZS',
            'pricing_mode' => 'packages',
            'cancellation_policy' => 'Package bookings are refundable until seven days before the event.',
        ], $attributes));
    }

    private function package(Event $event, array $attributes = []): EventPackage
    {
        return $event->packages()->create(array_merge([
            'name' => 'VIP',
            'description' => 'Priority seating and refreshments.',
            'price' => 25000,
            'capacity' => 10,
            'max_per_registration' => 3,
            'sort_order' => 1,
            'is_active' => true,
        ], $attributes));
    }

    private function invitee(Event $event, string $email = 'invited@example.com'): Invitee
    {
        return $event->invitees()->create([
            'name' => 'Invited Guest',
            'email' => $email,
            'identity_key' => hash('sha256', $email),
            'token' => hash('sha256', $email),
            'max_guests' => 4,
        ]);
    }

    public function test_public_guest_selects_one_package_and_the_server_freezes_the_exact_total(): void
    {
        $event = $this->event();
        $general = $this->package($event, ['name' => 'General', 'price' => 10000, 'sort_order' => 0]);
        $vip = $this->package($event);

        $this->get($event->registrationUrl())
            ->assertOk()
            ->assertSee('Choose your package')
            ->assertSee('General')
            ->assertSee('VIP')
            ->assertSee('name="event_package_id"', false);

        $payload = [
            'name' => 'Public Guest',
            'email' => 'public-package@example.com',
            'rsvp_status' => 'accepted',
            'attending_count' => 2,
            'checkout_acknowledged' => 1,
        ];
        $this->post($event->registrationUrl(), $payload)
            ->assertSessionHasErrors('event_package_id');

        $this->post($event->registrationUrl(), $payload + ['event_package_id' => $vip->id])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $guest = $event->invitees()->where('email', $payload['email'])->firstOrFail();
        $this->assertSame($vip->id, $guest->event_package_id);
        $this->assertSame('VIP', $guest->package_name_snapshot);
        $this->assertSame('25000.00', $guest->package_unit_price_snapshot);
        $this->assertSame('TZS', $guest->package_currency_snapshot);
        $this->assertSame('50000.00', data_get($guest->checkoutAcknowledgements()->latest('id')->firstOrFail()->snapshot, 'pricing.total'));
        $this->assertTrue($guest->accessPassEligible());

        $general->update(['price' => 9000]);
        $vip->update(['name' => 'VIP Plus', 'price' => 40000]);
        $this->get($guest->rsvpUrl())->assertOk()->assertSee('VIP')->assertSee('TZS 25,000.00');
        $this->get(route('rsvp.pass.show', $guest->token))->assertOk()->assertSee('VIP');
    }

    public function test_guest_cannot_select_an_archived_or_foreign_package(): void
    {
        $event = $this->event();
        $archived = $this->package($event, ['name' => 'Archived', 'is_active' => false]);
        $otherEvent = $this->event(['title' => 'Other package event']);
        $foreign = $this->package($otherEvent, ['name' => 'Foreign']);
        $payload = [
            'name' => 'Public Guest',
            'email' => 'invalid-package@example.com',
            'rsvp_status' => 'accepted',
            'attending_count' => 1,
            'checkout_acknowledged' => 1,
        ];

        foreach ([$archived, $foreign] as $package) {
            $this->post($event->registrationUrl(), $payload + ['event_package_id' => $package->id])
                ->assertSessionHasErrors('event_package_id');
        }

        $this->assertDatabaseMissing('invitees', ['email' => 'invalid-package@example.com']);
    }

    public function test_package_capacity_and_package_party_limit_are_enforced_during_registration(): void
    {
        $event = $this->event();
        $package = $this->package($event, ['capacity' => 2, 'max_per_registration' => 2]);
        $first = $this->invitee($event, 'first-package@example.com');
        $first->update(['rsvp_status' => 'accepted', 'attending_count' => 2]);
        app(EventPricingService::class)->assignPackage($first, $package, 2);

        $this->post($event->registrationUrl(), [
            'name' => 'Late Guest',
            'email' => 'late-package@example.com',
            'rsvp_status' => 'accepted',
            'event_package_id' => $package->id,
            'attending_count' => 1,
            'checkout_acknowledged' => 1,
        ])->assertSessionHasErrors('event_package_id');

        $this->assertDatabaseMissing('invitees', ['email' => 'late-package@example.com']);
    }

    public function test_guest_pages_default_to_the_first_package_that_can_still_be_selected(): void
    {
        $event = $this->event();
        $soldOut = $this->package($event, [
            'name' => 'General',
            'price' => 10000,
            'capacity' => 1,
            'sort_order' => 0,
        ]);
        $available = $this->package($event, ['name' => 'VIP', 'price' => 25000, 'sort_order' => 1]);
        $holder = $this->invitee($event, 'package-holder@example.com');
        $holder->update(['rsvp_status' => 'accepted', 'attending_count' => 1]);
        app(EventPricingService::class)->assignPackage($holder, $soldOut, 1);
        $invited = $this->invitee($event, 'package-choice@example.com');

        foreach ([$this->get($event->registrationUrl()), $this->get($invited->rsvpUrl())] as $response) {
            $response->assertOk();
            $html = $response->getContent();
            $this->assertMatchesRegularExpression('/id="(?:registration|rsvp)-package-'.$available->id.'"[^>]*\schecked(?:="checked")?/', $html);
            $this->assertDoesNotMatchRegularExpression('/id="(?:registration|rsvp)-package-'.$soldOut->id.'"[^>]*\schecked(?:="checked")?/', $html);
            $this->assertStringContainsString('TZS 25,000.00', $html);
        }
    }

    public function test_invite_only_professional_event_public_code_requires_otp_access(): void
    {
        Storage::fake('local');
        $event = $this->event(['visibility' => 'invite_only']);
        $package = $this->package($event);
        $guest = $this->invitee($event);
        $cardPath = 'events/'.$event->id.'/cards/private-invitation.png';
        Storage::disk('local')->put($cardPath, 'private-card');
        $event->update([
            'invitation_card_path' => $cardPath,
            'invitation_card_preview_path' => $cardPath,
            'invitation_card_name' => 'private-invitation.png',
        ]);

        $this->get($event->registrationUrl())
            ->assertRedirect(route('events.access.show', $event->public_code));
        $this->post($event->registrationUrl(), [
            'name' => 'Uninvited Guest',
            'email' => 'uninvited@example.com',
            'rsvp_status' => 'accepted',
            'attending_count' => 1,
            'event_package_id' => $package->id,
            'checkout_acknowledged' => 1,
        ])->assertNotFound();
        $this->get(route('events.registration.card', $event->public_code))->assertNotFound();
        $this->assertDatabaseMissing('invitees', ['email' => 'uninvited@example.com']);

        $this->get($guest->rsvpUrl())
            ->assertOk()
            ->assertSee($event->title)
            ->assertSee('Choose your package');
    }

    public function test_locked_paid_rsvp_keeps_its_saved_quantity_when_package_limits_are_reduced(): void
    {
        $event = $this->event(['participation_payment_timing' => 'pay_now']);
        $package = $this->package($event, ['price' => 25000, 'max_per_registration' => 3]);
        $guest = $this->invitee($event);
        $guest->update(['rsvp_status' => 'accepted', 'attending_count' => 3]);
        app(EventPricingService::class)->assignPackage($guest, $package, 3);
        $guest->update([
            'participation_payment_status' => 'paid',
            'confirmation_status' => 'confirmed',
        ]);
        $package->update(['max_per_registration' => 1]);

        $response = $this->get($guest->rsvpUrl())->assertOk()->assertSee('TZS 75,000.00');
        $html = $response->getContent();
        $this->assertMatchesRegularExpression('/id="rsvp-package-'.$package->id.'"[^>]*data-max="3"[^>]*\schecked(?:="checked")?[^>]*\sdisabled(?:="disabled")?/', $html);
        $this->assertStringContainsString('name="attending_count" value="3"', $html);
        $this->assertMatchesRegularExpression('/id="rsvp-attending-count"[^>]*value="3"[^>]*\sdisabled(?:="disabled")?/', $html);
    }

    public function test_invited_guest_package_change_resets_organiser_approval_before_payment(): void
    {
        $event = $this->event([
            'participation_payment_timing' => 'pay_now',
            'rsvp_confirmation_mode' => 'organizer_approval',
        ]);
        $general = $this->package($event, ['name' => 'General', 'price' => 10000, 'sort_order' => 0]);
        $vip = $this->package($event);
        $guest = $this->invitee($event);

        $this->post($guest->rsvpUrl(), [
            'rsvp_status' => 'accepted',
            'event_package_id' => $general->id,
            'attending_count' => 1,
        ])->assertSessionHasNoErrors();
        $this->assertSame('awaiting_approval', $guest->fresh()->confirmation_status);

        $guest->update(['confirmation_status' => 'awaiting_payment']);
        $this->post($guest->rsvpUrl(), [
            'rsvp_status' => 'accepted',
            'event_package_id' => $vip->id,
            'attending_count' => 1,
        ])->assertSessionHasNoErrors();

        $guest->refresh();
        $this->assertSame($vip->id, $guest->event_package_id);
        $this->assertSame('VIP', $guest->package_name_snapshot);
        $this->assertSame('awaiting_approval', $guest->confirmation_status);
        $this->assertSame('payment_required', $guest->participation_payment_status);
        $this->assertDatabaseCount('participation_payments', 0);
        $this->get(route('rsvp.payment.show', $guest->token))
            ->assertOk()
            ->assertSee('VIP')
            ->assertSee('TZS 25,000.00');
    }

    public function test_active_payment_locks_package_and_party_size_and_superseded_callback_is_ignored(): void
    {
        config(['services.snippe.webhook_secret' => null]);
        $event = $this->event(['participation_payment_timing' => 'pay_now']);
        $general = $this->package($event, ['name' => 'General', 'price' => 10000, 'sort_order' => 0]);
        $vip = $this->package($event);
        $guest = $this->invitee($event);
        $guest->update([
            'rsvp_status' => 'accepted',
            'attending_count' => 1,
            'participation_payment_status' => 'pending',
            'confirmation_status' => 'awaiting_payment',
        ]);
        app(EventPricingService::class)->assignPackage($guest, $general, 1);
        $payment = $guest->participationPayments()->create([
            'reference' => 'OBP-PACKAGE-PENDING',
            'callback_token' => str_repeat('k', 32),
            'msisdn' => '255656414320',
            'network' => 1,
            'amount' => 10000,
            'currency' => 'TZS',
            'status' => 'pending',
            'provider_payment_reference' => 'snippe-package-pending',
        ]);

        $this->post($guest->rsvpUrl(), [
            'rsvp_status' => 'accepted',
            'event_package_id' => $vip->id,
            'attending_count' => 2,
            'participation_payment_reference' => 'MANUAL-CHANGE',
            'checkout_acknowledged' => 1,
        ])->assertSessionHasErrors('event_package_id');
        $this->assertSame($general->id, $guest->fresh()->event_package_id);
        $this->assertSame(1, $guest->fresh()->attending_count);

        $payment->update(['status' => 'superseded']);
        $payload = [
            'type' => 'payment.completed',
            'data' => [
                'reference' => 'snippe-package-pending',
                'status' => 'completed',
                'amount' => ['value' => 10000, 'currency' => 'TZS'],
                'customer' => ['phone' => '+255656414320'],
            ],
        ];
        $this->postJson(route('api.payments.callback', $payment->callback_token), $payload)->assertOk();
        $this->assertSame('superseded', $payment->fresh()->status);
        $this->assertSame('pending', $guest->fresh()->participation_payment_status);
    }

    public function test_reported_manual_payment_locks_the_selected_package_during_review(): void
    {
        $event = $this->event([
            'participation_payment_timing' => 'pay_now',
            'participation_collection_method' => 'manual',
            'participation_payment_details' => 'Pay the organiser by mobile money.',
        ]);
        $general = $this->package($event, ['name' => 'General', 'price' => 10000, 'sort_order' => 0]);
        $vip = $this->package($event);
        $guest = $this->invitee($event);
        $guest->update([
            'rsvp_status' => 'accepted',
            'attending_count' => 1,
            'participation_payment_status' => 'payment_reported',
            'participation_payment_reference' => 'MANUAL-100',
            'confirmation_status' => 'awaiting_payment',
        ]);
        app(EventPricingService::class)->assignPackage($guest, $general, 1);

        $this->post($guest->rsvpUrl(), [
            'rsvp_status' => 'accepted',
            'event_package_id' => $vip->id,
            'attending_count' => 1,
            'participation_payment_reference' => 'MANUAL-200',
            'checkout_acknowledged' => 1,
        ])->assertSessionHasErrors('event_package_id');

        $guest->refresh();
        $this->assertSame($general->id, $guest->event_package_id);
        $this->assertSame('MANUAL-100', $guest->participation_payment_reference);
        $this->assertSame('payment_reported', $guest->participation_payment_status);
        $this->get($guest->rsvpUrl())
            ->assertOk()
            ->assertSee('locked while the organiser verifies your payment reference')
            ->assertSee('type="hidden" name="event_package_id" value="'.$general->id.'"', false)
            ->assertSee('type="hidden" name="attending_count" value="1"', false);
    }

    public function test_package_change_supersedes_failed_attempt_before_a_delayed_callback(): void
    {
        config(['services.snippe.webhook_secret' => null]);
        $event = $this->event([
            'participation_payment_timing' => 'pay_now',
            'participation_collection_method' => 'bluetick_pay',
            'rsvp_confirmation_mode' => 'organizer_approval',
        ]);
        $general = $this->package($event, ['name' => 'General', 'price' => 10000, 'sort_order' => 0]);
        $vip = $this->package($event);
        $guest = $this->invitee($event);
        $guest->update([
            'rsvp_status' => 'accepted',
            'attending_count' => 1,
            'participation_payment_status' => 'failed',
            'confirmation_status' => 'awaiting_payment',
        ]);
        app(EventPricingService::class)->assignPackage($guest, $general, 1);
        $oldPayment = $guest->participationPayments()->create([
            'reference' => 'OBP-PACKAGE-FAILED',
            'callback_token' => str_repeat('f', 32),
            'msisdn' => '255656414320',
            'network' => 1,
            'amount' => 10000,
            'currency' => 'TZS',
            'status' => 'failed',
            'provider_payment_reference' => 'snippe-package-failed',
        ]);

        $this->post($guest->rsvpUrl(), [
            'rsvp_status' => 'accepted',
            'event_package_id' => $vip->id,
            'attending_count' => 2,
        ])->assertSessionHasNoErrors();

        $guest->refresh();
        $this->assertSame('superseded', $oldPayment->fresh()->status);
        $this->assertSame($vip->id, $guest->event_package_id);
        $this->assertSame(2, $guest->attending_count);
        $this->assertSame('payment_required', $guest->participation_payment_status);
        $this->assertSame('awaiting_approval', $guest->confirmation_status);
        $this->assertNull($guest->fresh()->latestParticipationPayment);
        $this->get(route('rsvp.payment.show', $guest->token))
            ->assertOk()
            ->assertSee('VIP')
            ->assertSee('TZS 50,000.00')
            ->assertDontSee('TZS 10,000.00');

        $this->postJson(route('api.payments.callback', $oldPayment->callback_token), [
            'type' => 'payment.completed',
            'data' => [
                'reference' => 'snippe-package-failed',
                'status' => 'completed',
                'amount' => ['value' => 10000, 'currency' => 'TZS'],
                'customer' => ['phone' => '+255656414320'],
            ],
        ])->assertOk();

        $this->assertSame('superseded', $oldPayment->fresh()->status);
        $this->assertSame('payment_required', $guest->fresh()->participation_payment_status);
    }

    public function test_payment_retry_supersedes_the_previous_failed_request_before_creating_a_new_one(): void
    {
        config([
            'services.snippe.key' => 'snp_test_key',
            'services.snippe.webhook_secret' => null,
            'services.snippe.webhook_base_url' => null,
        ]);
        Http::fake([
            'api.snippe.sh/v1/payments' => Http::response([
                'status' => 'success',
                'code' => 201,
                'data' => ['reference' => 'snippe-package-retry', 'status' => 'pending'],
            ], 201),
        ]);
        $event = $this->event([
            'participation_payment_timing' => 'pay_now',
            'participation_collection_method' => 'bluetick_pay',
        ]);
        $event->user->organiserVerification()->create([
            'organiser_type' => 'professional',
            'verification_level' => 'identity',
            'status' => 'approved',
            'verified_at' => now(),
            'payout_name_verified_at' => now(),
        ]);
        $package = $this->package($event);
        $guest = $this->invitee($event);
        $guest->update([
            'rsvp_status' => 'accepted',
            'attending_count' => 1,
            'participation_payment_status' => 'payment_required',
            'confirmation_status' => 'awaiting_payment',
        ]);
        app(EventPricingService::class)->assignPackage($guest, $package, 1);
        $oldPayment = $guest->participationPayments()->create([
            'reference' => 'OBP-PACKAGE-REQUEST-FAILED',
            'callback_token' => str_repeat('r', 32),
            'msisdn' => '255656414320',
            'network' => 1,
            'amount' => 25000,
            'currency' => 'TZS',
            'status' => 'request_failed',
            'provider_payment_reference' => 'snippe-package-request-failed',
        ]);

        $this->post(route('rsvp.payment.store', $guest->token), [
            'attending_count' => 1,
            'event_package_id' => $package->id,
            'msisdn' => '0656414320',
            'checkout_acknowledged' => 1,
        ])->assertSessionHasNoErrors();

        $this->assertSame('superseded', $oldPayment->fresh()->status);
        $this->assertDatabaseHas('participation_payments', [
            'invitee_id' => $guest->id,
            'provider_payment_reference' => 'snippe-package-retry',
            'status' => 'pending',
            'amount' => 25000,
        ]);
        $this->assertSame('pending', $guest->fresh()->participation_payment_status);
        $this->assertSame('snippe-package-retry', $guest->fresh()->latestParticipationPayment?->provider_payment_reference);

        $this->postJson(route('api.payments.callback', $oldPayment->callback_token), [
            'type' => 'payment.completed',
            'data' => [
                'reference' => 'snippe-package-request-failed',
                'status' => 'completed',
                'amount' => ['value' => 25000, 'currency' => 'TZS'],
                'customer' => ['phone' => '+255656414320'],
            ],
        ])->assertOk();

        $this->assertSame('superseded', $oldPayment->fresh()->status);
        $this->assertSame('pending', $guest->fresh()->participation_payment_status);
    }

    public function test_organiser_rejection_locks_active_attempts_and_supersedes_a_failed_attempt(): void
    {
        config(['services.snippe.webhook_secret' => null]);
        $event = $this->event([
            'participation_payment_timing' => 'pay_now',
            'participation_collection_method' => 'bluetick_pay',
            'rsvp_confirmation_mode' => 'organizer_approval',
        ]);
        $package = $this->package($event, ['capacity' => 2]);
        $guest = $this->invitee($event);
        $guest->update([
            'rsvp_status' => 'accepted',
            'attending_count' => 1,
            'participation_payment_status' => 'payment_required',
            'confirmation_status' => 'awaiting_payment',
        ]);
        app(EventPricingService::class)->assignPackage($guest, $package, 1);
        $payment = $guest->participationPayments()->create([
            'reference' => 'OBP-REJECTION-RACE',
            'callback_token' => str_repeat('j', 32),
            'msisdn' => '255656414320',
            'network' => 1,
            'amount' => 25000,
            'currency' => 'TZS',
            'status' => 'pending',
            'provider_payment_reference' => 'snippe-rejection-race',
        ]);

        $this->actingAs($event->user)->put(route('events.rsvp-confirmations.update', [$event, $guest]), [
            'confirmation_status' => 'rejected',
        ])->assertSessionHasErrors('confirmation_status');
        $this->assertSame('awaiting_payment', $guest->fresh()->confirmation_status);

        $payment->update(['status' => 'request_failed']);
        $this->actingAs($event->user)->put(route('events.rsvp-confirmations.update', [$event, $guest]), [
            'confirmation_status' => 'rejected',
        ])->assertSessionHasNoErrors();
        $this->assertSame('superseded', $payment->fresh()->status);
        $this->assertSame('rejected', $guest->fresh()->confirmation_status);
        $this->assertSame(2, $package->fresh()->remainingCapacity());
        $this->assertFalse($guest->fresh()->accessPassEligible());

        $this->postJson(route('api.payments.callback', $payment->callback_token), [
            'type' => 'payment.completed',
            'data' => [
                'reference' => 'snippe-rejection-race',
                'status' => 'completed',
                'amount' => ['value' => 25000, 'currency' => 'TZS'],
                'customer' => ['phone' => '+255656414320'],
            ],
        ])->assertOk();

        $this->assertSame('superseded', $payment->fresh()->status);
        $this->assertSame('rejected', $guest->fresh()->confirmation_status);
        $this->assertSame('payment_required', $guest->fresh()->participation_payment_status);
        $this->get(route('rsvp.pass.show', $guest->token))->assertForbidden();
    }
}
