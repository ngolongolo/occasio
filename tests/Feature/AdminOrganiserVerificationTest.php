<?php

namespace Tests\Feature;

use App\Models\AdminAuditLog;
use App\Models\Event;
use App\Models\OrganiserVerification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

class AdminOrganiserVerificationTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::create(['name' => 'Trust Admin', 'email' => 'trust-admin@example.com', 'password' => 'passwordlong']);
        $admin->forceFill(['role' => 'super_admin'])->save();

        return $admin;
    }

    private function organiser(string $name = 'Asha Events', string $email = 'asha-review@example.com'): User
    {
        return User::create(['name' => $name, 'email' => $email, 'password' => 'passwordlong']);
    }

    private function application(User $organiser, array $attributes = []): OrganiserVerification
    {
        return $organiser->organiserVerification()->create(array_replace([
            'organiser_type' => OrganiserVerification::TYPE_ORGANISATION,
            'verification_level' => OrganiserVerification::LEVEL_BUSINESS,
            'status' => OrganiserVerification::STATUS_PENDING,
            'public_name' => 'Asha Events Limited',
            'public_contact_email' => 'support@asha.test',
            'public_contact_phone' => '+255784311111',
            'legal_name' => 'Asha Holdings Tanzania Limited',
            'registration_number' => 'BRELA-123456',
            'tax_identifier' => 'TIN-987654',
            'submitted_at' => now()->subDays(2),
        ], $attributes));
    }

    private function event(User $organiser, array $attributes = []): Event
    {
        return $organiser->events()->create(array_replace([
            'event_type' => 'event',
            'title' => 'Asha Business Forum',
            'host' => 'Asha Events',
            'description' => 'A professional business forum.',
            'starts_at' => now()->addMonth(),
            'rsvp_deadline' => now()->addWeeks(3),
            'venue' => 'Dar es Salaam',
            'visibility' => 'link',
            'participation_payment_timing' => 'pay_now',
            'participation_collection_method' => 'bluetick_pay',
            'participation_price' => 10000,
            'participation_currency' => 'TZS',
            'payout_payment_type' => 'MixxByYas',
            'payout_number' => '0656414320',
        ], $attributes));
    }

    public function test_only_system_admin_can_access_the_verification_queue(): void
    {
        $organiser = $this->organiser();
        $this->application($organiser);

        $this->actingAs($organiser)->get(route('admin.verifications.index'))->assertForbidden();
        $this->actingAs($this->admin())->get(route('admin.verifications.index'))
            ->assertOk()->assertSee('Organiser verification')->assertSee('Asha Events Limited');
    }

    public function test_pending_queue_is_oldest_first_and_status_tabs_filter_results(): void
    {
        $old = $this->organiser('Oldest Applicant', 'oldest@example.com');
        $new = $this->organiser('New Applicant', 'new@example.com');
        $approved = $this->organiser('Approved Applicant', 'approved@example.com');
        $this->application($old, ['public_name' => 'Oldest Applicant', 'submitted_at' => now()->subWeek()]);
        $this->application($new, ['public_name' => 'New Applicant', 'submitted_at' => now()->subDay()]);
        $this->application($approved, ['public_name' => 'Approved Applicant', 'status' => OrganiserVerification::STATUS_APPROVED, 'verified_at' => now()]);

        $this->actingAs($this->admin())->get(route('admin.verifications.index'))
            ->assertOk()->assertSeeInOrder(['Oldest Applicant', 'New Applicant'])->assertDontSee('Approved Applicant');

        $this->get(route('admin.verifications.index', ['status' => 'approved']))
            ->assertOk()->assertSee('Approved Applicant')->assertDontSee('Oldest Applicant');
    }

    public function test_review_page_shows_authorised_legal_fields_and_masked_operational_exposure(): void
    {
        $organiser = $this->organiser();
        $this->application($organiser);
        $this->event($organiser);

        $this->actingAs($this->admin())->get(route('admin.verifications.show', $organiser))
            ->assertOk()
            ->assertSee('Asha Events Limited')
            ->assertSee('Asha Holdings Tanzania Limited')
            ->assertSee('BRELA-123456')
            ->assertSee('TIN-987654')
            ->assertSee('Asha Business Forum')
            ->assertSee('4320')
            ->assertDontSee('0656414320');
    }

    public function test_identity_approval_and_payout_verification_are_separate_audited_actions(): void
    {
        $organiser = $this->organiser();
        $verification = $this->application($organiser, [
            'organiser_type' => OrganiserVerification::TYPE_PROFESSIONAL,
            'verification_level' => OrganiserVerification::LEVEL_IDENTITY,
            'registration_number' => null,
        ]);
        $this->event($organiser);
        $admin = $this->admin();

        $this->actingAs($admin)->put(route('admin.verifications.review', $organiser), [
            'action' => 'approve_identity',
            'verification_version' => $verification->reviewVersion(),
            'expires_at' => now()->addYear()->format('Y-m-d'),
            'reason' => 'Identity evidence checked.',
        ])->assertRedirect(route('admin.verifications.show', $organiser))->assertSessionHas('success');

        $verification->refresh();
        $this->assertTrue($verification->isApproved());
        $this->assertNull($verification->payout_name_verified_at);
        $this->assertDatabaseHas('admin_audit_logs', [
            'actor_id' => $admin->id,
            'subject_user_id' => $organiser->id,
            'action' => 'organiser_verification.approve_identity',
        ]);

        $this->put(route('admin.verifications.review', $organiser), [
            'action' => 'verify_payout',
            'verification_version' => $verification->reviewVersion(),
            'payout_confirmation' => '1',
        ])->assertRedirect(route('admin.verifications.show', $organiser));

        $this->assertNotNull($verification->fresh()->payout_name_verified_at);
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'organiser_verification.verify_payout']);
    }

    public function test_adverse_actions_require_a_reason_and_clear_financial_trust(): void
    {
        $organiser = $this->organiser();
        $verification = $this->application($organiser, [
            'status' => OrganiserVerification::STATUS_APPROVED,
            'verified_at' => now(),
            'payout_name_verified_at' => now(),
        ]);
        $admin = $this->admin();

        $this->actingAs($admin)->put(route('admin.verifications.review', $organiser), [
            'action' => 'suspend',
            'verification_version' => $verification->reviewVersion(),
        ])->assertSessionHasErrors('reason');

        $this->put(route('admin.verifications.review', $organiser), [
            'action' => 'request_changes',
            'verification_version' => $verification->reviewVersion(),
            'reason' => 'Upload a current registration certificate.',
        ])->assertRedirect(route('admin.verifications.show', $organiser));

        $verification->refresh();
        $this->assertSame(OrganiserVerification::STATUS_CHANGES_REQUESTED, $verification->status);
        $this->assertNull($verification->verified_at);
        $this->assertNull($verification->payout_name_verified_at);

        $this->actingAs($organiser)->get(route('organiser.verification.show'))
            ->assertOk()->assertSee('Upload a current registration certificate.');
    }

    public function test_stale_review_is_rejected_without_overwriting_a_newer_decision(): void
    {
        $organiser = $this->organiser();
        $verification = $this->application($organiser);
        $staleVersion = $verification->reviewVersion();
        $verification->update(['public_name' => 'Updated applicant name']);

        $this->actingAs($this->admin())->put(route('admin.verifications.review', $organiser), [
            'action' => 'approve_business',
            'verification_version' => $staleVersion,
        ])->assertSessionHasErrors('action');

        $this->assertSame(OrganiserVerification::STATUS_PENDING, $verification->fresh()->status);
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    public function test_audit_snapshots_exclude_private_evidence_and_records_are_immutable(): void
    {
        $organiser = $this->organiser();
        $verification = $this->application($organiser);
        $admin = $this->admin();

        $this->actingAs($admin)->put(route('admin.verifications.review', $organiser), [
            'action' => 'approve_business',
            'verification_version' => $verification->reviewVersion(),
            'reason' => 'BRELA-123456 and TIN-987654 checked privately.',
        ])->assertRedirect();

        $stored = DB::table('admin_audit_logs')->first();
        $snapshot = ($stored->before_state ?? '').($stored->after_state ?? '');
        $this->assertStringNotContainsString('BRELA-123456', $snapshot);
        $this->assertStringNotContainsString('TIN-987654', $snapshot);
        $this->assertStringNotContainsString('Asha Holdings Tanzania Limited', $snapshot);
        $this->assertNotSame('BRELA-123456 and TIN-987654 checked privately.', $stored->reason);

        $this->expectException(LogicException::class);
        AdminAuditLog::firstOrFail()->update(['action' => 'tampered']);
    }

    public function test_business_approval_requires_a_registration_number(): void
    {
        $organiser = $this->organiser();
        $verification = $this->application($organiser, ['registration_number' => null]);

        $this->actingAs($this->admin())->put(route('admin.verifications.review', $organiser), [
            'action' => 'approve_business',
            'verification_version' => $verification->reviewVersion(),
        ])->assertSessionHasErrors('action');

        $this->assertSame(OrganiserVerification::STATUS_PENDING, $verification->fresh()->status);
    }
}
