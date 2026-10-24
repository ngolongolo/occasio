<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\ParticipationPayment;
use App\Models\User;
use App\Services\CheckoutDisclosure;
use App\Services\ParticipationPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CheckoutDisclosureTest extends TestCase
{
    use RefreshDatabase;

    private function paidEvent(array $attributes = []): Event
    {
        $organiser = User::create([
            'name' => 'Event Host',
            'email' => uniqid('host-', true).'@example.com',
            'password' => 'passwordlong',
        ]);

        $event = Event::create(array_merge([
            'user_id' => $organiser->id,
            'title' => 'Paid Gathering',
            'host' => 'Event Host',
            'description' => 'An event with paid attendance.',
            'starts_at' => now()->addMonth(),
            'rsvp_deadline' => now()->addWeeks(3),
            'venue' => 'Dar es Salaam',
            'participation_payment_timing' => 'pay_now',
            'participation_collection_method' => 'bluetick_pay',
            'participation_price' => 10000,
            'participation_currency' => 'TZS',
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

    public function test_paid_registration_requires_consent_and_binds_an_immutable_snapshot_to_payment(): void
    {
        config(['services.snippe.key' => 'snp_test_key', 'services.snippe.webhook_base_url' => null]);
        Http::fake(['api.snippe.sh/v1/payments' => Http::response([
            'status' => 'success',
            'data' => ['reference' => 'snippe-disclosure-1', 'status' => 'pending'],
        ], 201)]);
        $event = $this->paidEvent(['visibility' => 'link', 'plus_ones_allowed' => true, 'max_attendees_per_registration' => 3]);
        $payload = [
            'name' => 'Paying Guest',
            'email' => 'paying@example.com',
            'phone' => '+255656414320',
            'attending_count' => 2,
        ];

        $this->post($event->registrationUrl(), $payload)
            ->assertSessionHasErrors('checkout_acknowledged');
        $this->assertDatabaseCount('participation_payments', 0);

        $this->post($event->registrationUrl(), $payload + ['checkout_acknowledged' => '1'])
            ->assertRedirect();

        $payment = ParticipationPayment::with('checkoutAcknowledgement')->firstOrFail();
        $snapshot = $payment->checkoutAcknowledgement->snapshot;
        $this->assertSame('20000.00', data_get($snapshot, 'pricing.total'));
        $this->assertSame(2, data_get($snapshot, 'attendance.count'));
        $this->assertSame(CheckoutDisclosure::ACKNOWLEDGEMENT, $snapshot['acknowledgement_text']);
        $this->assertSame($payment->invitee_id, $payment->checkoutAcknowledgement->invitee_id);
    }

    public function test_organiser_approval_happens_before_payment_and_payment_then_confirms_access(): void
    {
        config(['services.snippe.key' => 'snp_test_key', 'services.snippe.webhook_secret' => null, 'services.snippe.webhook_base_url' => null]);
        Http::fake(['api.snippe.sh/v1/payments' => Http::response([
            'status' => 'success',
            'data' => ['reference' => 'snippe-approved-1', 'status' => 'pending'],
        ], 201)]);
        $event = $this->paidEvent(['rsvp_confirmation_mode' => 'organizer_approval']);
        $guest = $event->invitees()->create([
            'name' => 'Approval Guest',
            'email' => 'approval@example.com',
            'identity_key' => hash('sha256', 'approval@example.com'),
            'token' => str_repeat('q', 64),
            'max_guests' => 2,
        ]);

        $this->post($guest->rsvpUrl(), ['rsvp_status' => 'accepted', 'attending_count' => 2])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        $guest->refresh();
        $this->assertSame('awaiting_approval', $guest->confirmation_status);
        $this->assertSame('payment_required', $guest->participation_payment_status);
        $this->assertDatabaseCount('participation_payments', 0);
        $this->assertDatabaseCount('checkout_acknowledgements', 0);

        $organiser = User::findOrFail($event->user_id);
        $this->actingAs($organiser)->put(route('events.rsvp-confirmations.update', [$event, $guest]), [
            'confirmation_status' => 'confirmed',
        ])->assertRedirect()->assertSessionHas('success');
        $guest->refresh();
        $this->assertSame('awaiting_payment', $guest->confirmation_status);
        $this->assertNull($guest->access_pass_issued_at);

        $this->post($guest->rsvpUrl(), [
            'rsvp_status' => 'accepted',
            'attending_count' => 2,
            'payment_msisdn' => '0656414320',
            'checkout_acknowledged' => '1',
        ])->assertRedirect(route('rsvp.payment.show', $guest->token));
        $payment = $guest->participationPayments()->firstOrFail();
        $this->assertSame('awaiting_payment', $guest->fresh()->confirmation_status);
        $this->assertNotNull($payment->checkout_acknowledgement_id);
        $this->assertSame(2, data_get($payment->checkoutAcknowledgement->snapshot, 'attendance.maximum_allowed'));

        $payload = [
            'type' => 'payment.completed',
            'data' => [
                'reference' => 'snippe-approved-1',
                'status' => 'completed',
                'amount' => ['value' => 20000, 'currency' => 'TZS'],
                'customer' => ['phone' => '+255656414320'],
            ],
        ];
        $this->postJson(route('api.payments.callback', $payment->callback_token), $payload)->assertOk();
        $guest->refresh();
        $this->assertSame('confirmed', $guest->confirmation_status);
        $this->assertTrue($guest->accessPassEligible());
        $this->assertNotNull($guest->access_pass_issued_at);
    }

    public function test_payment_rejects_an_acknowledgement_after_disclosed_details_change(): void
    {
        config(['services.snippe.key' => 'snp_test_key']);
        Http::preventStrayRequests();
        $event = $this->paidEvent();
        $guest = $event->invitees()->create([
            'name' => 'Disclosure Guest',
            'email' => 'disclosure@example.com',
            'identity_key' => hash('sha256', 'disclosure@example.com'),
            'token' => str_repeat('d', 64),
            'max_guests' => 1,
            'rsvp_status' => 'accepted',
            'attending_count' => 1,
            'participation_payment_status' => 'payment_required',
            'confirmation_status' => 'awaiting_payment',
        ]);
        $acknowledgement = app(CheckoutDisclosure::class)->acknowledge($guest, 1);
        $event->update(['participation_price' => 12000]);

        try {
            app(ParticipationPaymentService::class)->initiate($guest, '0656414320', 1, $acknowledgement);
            $this->fail('A stale acknowledgement was accepted.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertArrayHasKey('checkout_acknowledged', $exception->errors());
        }

        $this->assertDatabaseCount('participation_payments', 0);
        Http::assertNothingSent();
    }

    public function test_pay_at_event_approval_records_consent_before_the_request_is_sent(): void
    {
        $event = $this->paidEvent([
            'participation_payment_timing' => 'pay_at_event',
            'rsvp_confirmation_mode' => 'organizer_approval',
        ]);
        $guest = $event->invitees()->create([
            'name' => 'Door Payment Guest',
            'email' => 'door@example.com',
            'identity_key' => hash('sha256', 'door@example.com'),
            'token' => str_repeat('o', 64),
            'max_guests' => 1,
        ]);

        $payload = ['rsvp_status' => 'accepted', 'attending_count' => 1];
        $this->post($guest->rsvpUrl(), $payload)->assertSessionHasErrors('checkout_acknowledged');
        $this->post($guest->rsvpUrl(), $payload + ['checkout_acknowledged' => 1])->assertSessionHasNoErrors();
        $guest->refresh();
        $this->assertSame('awaiting_approval', $guest->confirmation_status);
        $this->assertSame('due_at_event', $guest->participation_payment_status);
        $this->assertDatabaseCount('checkout_acknowledgements', 1);

        $this->actingAs($event->user)->put(route('events.rsvp-confirmations.update', [$event, $guest]), [
            'confirmation_status' => 'confirmed',
        ])->assertSessionHasNoErrors();
        $guest->refresh();
        $this->assertTrue($guest->accessPassEligible());
        $this->assertNotNull($guest->access_pass_issued_at);
    }

    public function test_pending_payment_keeps_the_acknowledged_total_if_event_price_changes(): void
    {
        config(['services.snippe.key' => 'snp_test_key', 'services.snippe.webhook_secret' => null]);
        Http::fake(['api.snippe.sh/v1/payments' => Http::response([
            'status' => 'success',
            'data' => ['reference' => 'snippe-fixed-total', 'status' => 'pending'],
        ], 201)]);
        $event = $this->paidEvent();
        $guest = $event->invitees()->create([
            'name' => 'Fixed Total Guest',
            'email' => 'fixed@example.com',
            'identity_key' => hash('sha256', 'fixed@example.com'),
            'token' => str_repeat('f', 64),
            'max_guests' => 1,
            'rsvp_status' => 'accepted',
            'attending_count' => 1,
            'participation_payment_status' => 'payment_required',
            'confirmation_status' => 'awaiting_payment',
        ]);
        $acknowledgement = app(CheckoutDisclosure::class)->acknowledge($guest, 1);
        $payment = app(ParticipationPaymentService::class)->initiate($guest, '0656414320', 1, $acknowledgement);
        $event->update(['participation_price' => 12000]);

        $this->postJson(route('api.payments.callback', $payment->callback_token), [
            'type' => 'payment.completed',
            'data' => [
                'reference' => 'snippe-fixed-total',
                'status' => 'completed',
                'amount' => ['value' => 10000, 'currency' => 'TZS'],
                'customer' => ['phone' => '+255656414320'],
            ],
        ])->assertOk();

        $this->assertSame('paid', $payment->fresh()->status);
        $this->assertSame('10000.00', data_get($payment->checkoutAcknowledgement->snapshot, 'pricing.total'));
    }

    public function test_online_payment_is_not_started_after_organiser_payment_verification_expires(): void
    {
        config(['services.snippe.key' => 'snp_test_key']);
        Http::preventStrayRequests();
        $event = $this->paidEvent();
        $guest = $event->invitees()->create([
            'name' => 'Protected Guest',
            'email' => 'protected@example.com',
            'identity_key' => hash('sha256', 'protected@example.com'),
            'token' => str_repeat('e', 64),
            'max_guests' => 1,
            'rsvp_status' => 'accepted',
            'attending_count' => 1,
            'participation_payment_status' => 'payment_required',
            'confirmation_status' => 'awaiting_payment',
        ]);
        $acknowledgement = app(CheckoutDisclosure::class)->acknowledge($guest, 1);
        $event->user->organiserVerification()->update(['expires_at' => now()->subMinute()]);

        try {
            app(ParticipationPaymentService::class)->initiate($guest, '0656414320', 1, $acknowledgement);
            $this->fail('Payment started after organiser verification expired.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertSame(
                "Online payment is temporarily unavailable while the organiser's payment account is reviewed. No charge was initiated.",
                $exception->errors()['payment_msisdn'][0],
            );
        }

        $this->assertDatabaseCount('participation_payments', 0);
        Http::assertNothingSent();
    }

    public function test_public_approval_registration_returns_to_the_private_rsvp_before_payment(): void
    {
        config(['services.snippe.key' => 'snp_test_key']);
        Http::preventStrayRequests();
        $event = $this->paidEvent([
            'visibility' => 'link',
            'rsvp_confirmation_mode' => 'organizer_approval',
        ]);
        $payload = [
            'name' => 'Public Approval Guest',
            'email' => 'public-approval@example.com',
            'attending_count' => 1,
        ];

        $this->post($event->registrationUrl(), $payload)->assertRedirect();
        $guest = $event->invitees()->where('email', $payload['email'])->firstOrFail();
        $this->assertSame('awaiting_approval', $guest->confirmation_status);
        $this->assertDatabaseCount('checkout_acknowledgements', 0);
        $this->assertDatabaseCount('participation_payments', 0);

        $this->post(route('rsvp.payment.store', $guest->token), [
            'attending_count' => 1,
            'msisdn' => '0656414320',
            'checkout_acknowledged' => 1,
        ])->assertSessionHasErrors('msisdn');
        $this->assertDatabaseCount('checkout_acknowledgements', 0);

        $this->actingAs($event->user)->put(route('events.rsvp-confirmations.update', [$event, $guest]), [
            'confirmation_status' => 'confirmed',
        ])->assertSessionHasNoErrors();
        $this->assertSame('awaiting_payment', $guest->fresh()->confirmation_status);

        $this->post($event->registrationUrl(), $payload)
            ->assertRedirect(route('rsvp.show', $guest->token))
            ->assertSessionHasNoErrors();
        $this->assertDatabaseCount('participation_payments', 0);
        Http::assertNothingSent();
    }

    public function test_organiser_cannot_reject_registration_after_payment_has_started(): void
    {
        $event = $this->paidEvent(['rsvp_confirmation_mode' => 'organizer_approval']);
        $guest = $event->invitees()->create([
            'name' => 'Pending Payment Guest',
            'email' => 'pending-approval@example.com',
            'identity_key' => hash('sha256', 'pending-approval@example.com'),
            'token' => str_repeat('n', 64),
            'max_guests' => 1,
            'rsvp_status' => 'accepted',
            'attending_count' => 1,
            'participation_payment_status' => 'pending',
            'confirmation_status' => 'awaiting_payment',
        ]);

        $this->actingAs($event->user)->put(route('events.rsvp-confirmations.update', [$event, $guest]), [
            'confirmation_status' => 'rejected',
        ])->assertSessionHasErrors('confirmation_status');

        $this->assertSame('awaiting_payment', $guest->fresh()->confirmation_status);
    }
}
