<?php

namespace Tests\Feature;

use App\Models\OrganiserVerification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OrganiserVerificationTest extends TestCase
{
    use RefreshDatabase;

    private function organiser(): User
    {
        return User::create([
            'name' => 'Asha Events',
            'email' => 'asha@example.com',
            'password' => 'passwordlong',
        ]);
    }

    public function test_verification_is_separate_from_the_users_role(): void
    {
        $organiser = $this->organiser();

        $organiser->organiserVerification()->create([
            'organiser_type' => OrganiserVerification::TYPE_PROFESSIONAL,
            'verification_level' => OrganiserVerification::LEVEL_IDENTITY,
            'status' => OrganiserVerification::STATUS_APPROVED,
            'verified_at' => now(),
        ]);

        $organiser->refresh();

        $this->assertSame('organiser', $organiser->role);
        $this->assertTrue($organiser->isVerifiedOrganiser());
        $this->assertSame('Identity verified', $organiser->organiserVerificationSummary()['badge_label']);
    }

    public function test_an_expired_approval_is_not_displayed_as_verified(): void
    {
        $organiser = $this->organiser();
        $organiser->organiserVerification()->create([
            'organiser_type' => OrganiserVerification::TYPE_ORGANISATION,
            'verification_level' => OrganiserVerification::LEVEL_BUSINESS,
            'status' => OrganiserVerification::STATUS_APPROVED,
            'verified_at' => now()->subYear(),
            'expires_at' => now()->subMinute(),
        ]);

        $summary = $organiser->fresh()->organiserVerificationSummary();

        $this->assertFalse($organiser->fresh()->isVerifiedOrganiser());
        $this->assertSame(OrganiserVerification::STATUS_EXPIRED, $summary['verification_status']);
        $this->assertSame(OrganiserVerification::LEVEL_NONE, $summary['verification_level']);
        $this->assertNull($summary['badge_label']);
    }

    public function test_paid_collection_requires_current_verification_and_a_verified_payout_name(): void
    {
        $organiser = $this->organiser();
        $verification = $organiser->organiserVerification()->create([
            'organiser_type' => OrganiserVerification::TYPE_ORGANISATION,
            'verification_level' => OrganiserVerification::LEVEL_BUSINESS,
            'status' => OrganiserVerification::STATUS_APPROVED,
            'verified_at' => now(),
        ]);

        $this->assertFalse($organiser->fresh()->canCollectParticipationPayments());

        $verification->update(['payout_name_verified_at' => now()]);

        $this->assertTrue($organiser->fresh()->canCollectParticipationPayments());
    }

    public function test_sensitive_verification_fields_are_encrypted_and_not_in_public_summary(): void
    {
        $organiser = $this->organiser();
        $verification = $organiser->organiserVerification()->create([
            'organiser_type' => OrganiserVerification::TYPE_ORGANISATION,
            'legal_name' => 'Asha Events Limited',
            'registration_number' => 'BRELA-123456',
            'tax_identifier' => 'TIN-987654',
            'review_notes' => 'Reviewed against the submitted documents.',
        ]);

        $stored = DB::table('organiser_verifications')->find($verification->id);

        $this->assertNotSame('Asha Events Limited', $stored->legal_name);
        $this->assertNotSame('BRELA-123456', $stored->registration_number);
        $this->assertArrayNotHasKey('legal_name', $verification->publicSummary());
        $this->assertArrayNotHasKey('registration_number', $verification->publicSummary());
        $this->assertArrayNotHasKey('tax_identifier', $verification->publicSummary());
        $this->assertArrayNotHasKey('review_notes', $verification->publicSummary());
    }

    public function test_organiser_can_submit_details_without_approving_their_own_badge(): void
    {
        $organiser = $this->organiser();

        $this->actingAs($organiser)->post(route('organiser.verification.store'), [
            'organiser_type' => OrganiserVerification::TYPE_PROFESSIONAL,
            'verification_level' => OrganiserVerification::LEVEL_IDENTITY,
            'public_name' => 'Asha Events',
            'public_contact_email' => 'support@asha.test',
            'public_contact_phone' => '+255784311111',
            'legal_name' => 'Asha Mushi',
        ])->assertRedirect(route('organiser.verification.show'));

        $verification = $organiser->fresh()->organiserVerification;
        $this->assertSame(OrganiserVerification::STATUS_PENDING, $verification->status);
        $this->assertFalse($organiser->fresh()->isVerifiedOrganiser());
        $this->assertNull($verification->badgeLabel());
    }

    public function test_super_admin_can_approve_identity_and_payout_name_separately_from_role(): void
    {
        $organiser = $this->organiser();
        $organiser->organiserVerification()->create([
            'organiser_type' => OrganiserVerification::TYPE_ORGANISATION,
            'verification_level' => OrganiserVerification::LEVEL_BUSINESS,
            'status' => OrganiserVerification::STATUS_PENDING,
            'public_name' => 'Asha Events Limited',
            'public_contact_email' => 'support@asha.test',
            'submitted_at' => now(),
        ]);
        $admin = User::create(['name' => 'Admin', 'email' => 'verification-admin@example.com', 'password' => 'passwordlong']);
        $admin->forceFill(['role' => 'super_admin'])->save();

        $this->actingAs($admin)->put(route('admin.users.verification', $organiser), [
            'organiser_type' => OrganiserVerification::TYPE_ORGANISATION,
            'verification_level' => OrganiserVerification::LEVEL_BUSINESS,
            'status' => OrganiserVerification::STATUS_APPROVED,
            'public_name' => 'Asha Events Limited',
            'public_contact_email' => 'support@asha.test',
            'payout_name_verified' => '1',
            'review_notes' => 'Documents and payout account matched.',
        ])->assertRedirect()->assertSessionHas('success');

        $organiser->refresh();
        $this->assertSame('organiser', $organiser->role);
        $this->assertTrue($organiser->isVerifiedOrganiser());
        $this->assertTrue($organiser->canCollectParticipationPayments());
        $this->assertSame($admin->id, $organiser->organiserVerification->reviewed_by);
    }

    public function test_standard_organiser_can_create_an_invite_only_paid_event_with_manual_collection(): void
    {
        $organiser = $this->organiser();
        $payload = array_replace($this->paidEventPayload(), [
            'title' => 'Private paid event',
            'visibility' => 'invite_only',
            'participation_collection_method' => 'manual',
            'participation_payment_details' => 'Pay TZS 10,000 to 0712 345 678.',
            'payout_payment_type' => null,
            'payout_number' => null,
        ]);

        $response = $this->actingAs($organiser)->post('/events', $payload);
        $event = $organiser->events()->where('title', 'Private paid event')->firstOrFail();

        $response->assertRedirect(route('events.show', $event));
        $this->assertSame('invite_only', $event->visibility);
        $this->assertSame('pay_now', $event->participation_payment_timing);
        $this->assertSame('manual', $event->participation_collection_method);
    }

    public function test_standard_organiser_cannot_create_a_public_paid_event_and_is_prompted_to_verify(): void
    {
        $organiser = $this->organiser();

        $response = $this->actingAs($organiser)->post('/events', $this->paidEventPayload())
            ->assertSessionHasErrors('visibility');

        $this->assertDatabaseMissing('events', ['title' => 'Verified paid event']);
        $message = $response->getSession()->get('errors')->first('visibility');
        $this->assertStringContainsString('verif', strtolower($message));
    }

    public function test_standard_organiser_cannot_make_an_existing_private_paid_event_public(): void
    {
        $organiser = $this->organiser();
        $event = $organiser->events()->create([
            'event_type' => 'event',
            'title' => 'Private workshop',
            'host' => 'Asha Events',
            'description' => 'An invitation-only paid workshop.',
            'starts_at' => now()->addMonth(),
            'rsvp_deadline' => now()->addWeeks(3),
            'venue' => 'Dar es Salaam',
            'contact' => '+255784311111',
            'visibility' => 'invite_only',
            'participation_payment_timing' => 'pay_now',
            'participation_collection_method' => 'manual',
            'participation_price' => 10000,
            'participation_currency' => 'TZS',
            'participation_payment_details' => 'Pay TZS 10,000 to 0712 345 678.',
            'cancellation_policy' => 'Refunds are available until 48 hours before the event.',
        ]);

        $response = $this->actingAs($organiser)->put(route('events.design.update', $event), [
            'visibility' => 'link',
            'primary_color' => '#56283f',
            'background_color' => '#fcf9f4',
            'text_color' => '#30252a',
            'sms_template' => 'Hello {guest_name}. RSVP: {rsvp_url}',
        ])->assertSessionHasErrors('visibility');

        $this->assertSame('invite_only', $event->fresh()->visibility);
        $message = $response->getSession()->get('errors')->first('visibility');
        $this->assertStringContainsString('verif', strtolower($message));
    }

    public function test_paid_event_support_contact_cannot_be_cleared_on_edit(): void
    {
        $organiser = $this->organiser();
        $event = $organiser->events()->create([
            'event_type' => 'event',
            'title' => 'Private paid workshop',
            'host' => 'Asha Events',
            'description' => 'An invitation-only paid workshop.',
            'starts_at' => now()->addMonth(),
            'rsvp_deadline' => now()->addWeeks(3),
            'venue' => 'Dar es Salaam',
            'contact' => '+255784311111',
            'visibility' => 'invite_only',
            'participation_payment_timing' => 'pay_now',
            'participation_collection_method' => 'manual',
            'participation_price' => 10000,
            'participation_currency' => 'TZS',
            'participation_payment_details' => 'Pay TZS 10,000 to 0712 345 678.',
            'cancellation_policy' => 'Refunds are available until 48 hours before the event.',
        ]);

        $response = $this->actingAs($organiser)->put(route('events.design.update', $event), [
            'contact' => '',
            'primary_color' => '#56283f',
            'background_color' => '#fcf9f4',
            'text_color' => '#30252a',
            'sms_template' => 'Hello {guest_name}. RSVP: {rsvp_url}',
            'participation_payment_details' => $event->participation_payment_details,
        ]);

        $response->assertSessionHasErrors('contact');
        $this->assertSame('+255784311111', $event->fresh()->contact);
    }

    public function test_pay_at_event_collection_is_normalized_to_manual_on_create_and_edit(): void
    {
        $organiser = $this->organiser();
        $payload = array_replace($this->paidEventPayload(), [
            'title' => 'Pay at the door workshop',
            'visibility' => 'invite_only',
            'participation_payment_timing' => 'pay_at_event',
            'participation_collection_method' => 'bluetick_pay',
            'payout_payment_type' => null,
            'payout_number' => null,
        ]);

        $this->actingAs($organiser)->post('/events', $payload)->assertRedirect();
        $event = $organiser->events()->where('title', 'Pay at the door workshop')->firstOrFail();
        $this->assertSame('manual', $event->participation_collection_method);

        DB::table('events')->where('id', $event->id)->update(['participation_collection_method' => 'bluetick_pay']);
        $event->refresh();
        $this->assertSame('bluetick_pay', $event->participation_collection_method);

        $this->actingAs($organiser)->put(route('events.design.update', $event), [
            'primary_color' => '#56283f',
            'background_color' => '#fcf9f4',
            'text_color' => '#30252a',
            'sms_template' => 'Hello {guest_name}. RSVP: {rsvp_url}',
            'contact' => $event->contact,
            'participation_payment_timing' => 'pay_at_event',
            'participation_collection_method' => 'bluetick_pay',
            'pricing_mode' => 'single',
            'participation_price' => $event->participation_price,
            'participation_currency' => $event->participation_currency,
            'cancellation_policy' => $event->cancellation_policy,
        ])->assertRedirect(route('events.design', $event))->assertSessionHasNoErrors();

        $this->assertSame('manual', $event->fresh()->participation_collection_method);
    }

    public function test_legacy_pay_at_event_bluetick_row_uses_manual_ledger_and_cannot_settle(): void
    {
        $organiser = $this->organiser();
        $event = $organiser->events()->create([
            'event_type' => 'event',
            'title' => 'Legacy door collection',
            'host' => 'Asha Events',
            'description' => 'An existing pay-at-event record with a stale collection method.',
            'starts_at' => now()->addMonth(),
            'rsvp_deadline' => now()->addWeeks(3),
            'venue' => 'Dar es Salaam',
            'contact' => '+255784311111',
            'visibility' => 'invite_only',
            'participation_payment_timing' => 'pay_at_event',
            'participation_collection_method' => 'bluetick_pay',
            'participation_price' => 5000,
            'participation_currency' => 'TZS',
            'cancellation_policy' => 'No refunds after check-in.',
        ]);
        $event->invitees()->create([
            'name' => 'Legacy Door Guest',
            'email' => 'legacy-door@example.com',
            'identity_key' => hash('sha256', 'legacy-door@example.com'),
            'token' => str_repeat('d', 64),
            'max_guests' => 2,
            'rsvp_status' => 'accepted',
            'attending_count' => 2,
            'participation_payment_status' => 'paid',
            'confirmation_status' => 'confirmed',
            'responded_at' => now(),
        ]);

        $this->actingAs($organiser)->get(route('events.show', $event))
            ->assertOk()
            ->assertViewHas('paymentSummary', fn ($summary) => (float) $summary['collected'] === 10000.0);

        $this->get(route('events.payments', $event))
            ->assertOk()
            ->assertViewHas('isBluetickParticipation', false)
            ->assertSee('Manual collection')
            ->assertSee('Legacy Door Guest')
            ->assertDontSee('Bluetick Pay · Snippe')
            ->assertDontSee('Withdraw to selected account');

        $this->post(route('events.settlements.store', $event), ['amount' => 5000])->assertNotFound();
    }

    public function test_standard_organiser_cannot_enable_bluetick_pay_for_an_invite_only_event(): void
    {
        $organiser = $this->organiser();
        $payload = array_replace($this->paidEventPayload(), [
            'title' => 'Private Bluetick event',
            'visibility' => 'invite_only',
        ]);

        $response = $this->actingAs($organiser)->post('/events', $payload)
            ->assertSessionHasErrors('payout_number');

        $this->assertDatabaseMissing('events', ['title' => 'Private Bluetick event']);
        $message = $response->getSession()->get('errors')->first('payout_number');
        $this->assertStringContainsString('verif', strtolower($message));
    }

    public function test_verified_organiser_with_matched_payout_can_create_a_public_paid_event(): void
    {
        $organiser = $this->organiser();
        $organiser->organiserVerification()->create([
            'organiser_type' => OrganiserVerification::TYPE_ORGANISATION,
            'verification_level' => OrganiserVerification::LEVEL_BUSINESS,
            'status' => OrganiserVerification::STATUS_APPROVED,
            'public_name' => 'Asha Events',
            'public_contact_email' => 'support@asha.test',
            'verified_at' => now(),
            'payout_name_verified_at' => now(),
        ]);

        $response = $this->actingAs($organiser)->post('/events', $this->paidEventPayload());
        $event = $organiser->events()->where('title', 'Verified paid event')->firstOrFail();

        $response->assertRedirect(route('events.show', $event));
        $this->assertSame('Refunds are available until 48 hours before the event.', $event->cancellation_policy);
    }

    private function paidEventPayload(): array
    {
        return [
            'event_type' => 'event',
            'title' => 'Verified paid event',
            'host' => 'Asha Events',
            'description' => 'A professional event with paid attendance.',
            'starts_at' => now()->addMonth()->format('Y-m-d H:i:s'),
            'rsvp_deadline' => now()->addWeeks(3)->format('Y-m-d'),
            'venue' => 'Dar es Salaam',
            'contact' => '+255784311111',
            'visibility' => 'link',
            'participation_payment_timing' => 'pay_now',
            'participation_collection_method' => 'bluetick_pay',
            'participation_price' => 10000,
            'participation_currency' => 'TZS',
            'payout_payment_type' => 'MixxByYas',
            'payout_number' => '0656414320',
            'cancellation_policy' => 'Refunds are available until 48 hours before the event.',
        ];
    }
}
