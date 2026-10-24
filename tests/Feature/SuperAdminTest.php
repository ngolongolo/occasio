<?php

namespace Tests\Feature;

use App\Models\{AdminAuditLog, Event, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SuperAdminTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $email, string $role = 'organiser'): User
    {
        $user = User::create(['name' => ucfirst(strstr($email, '@', true)), 'email' => $email, 'password' => 'passwordlong']);
        $user->forceFill(['role' => $role])->save();
        return $user;
    }

    private function event(User $owner, array $attributes = []): Event
    {
        return Event::create(array_merge([
            'user_id' => $owner->id,
            'title' => 'Owner Event',
            'host' => 'Owner',
            'description' => 'Event details',
            'starts_at' => now()->addWeek(),
            'rsvp_deadline' => now()->addDays(4),
            'venue' => 'Mwanza',
        ], $attributes));
    }

    public function test_organiser_cannot_access_super_admin_console(): void
    {
        $this->actingAs($this->user('organiser@example.com'))->get(route('admin.index'))->assertForbidden();
    }

    public function test_super_admin_can_oversee_and_open_another_organisers_event(): void
    {
        $owner = $this->user('owner@example.com');
        $admin = $this->user('admin@example.com', 'super_admin');
        $event = $this->event($owner);

        $this->actingAs($admin)->get(route('admin.index'))->assertOk()->assertSee('Platform oversight')->assertSee($event->title);
        $this->actingAs($admin)->get(route('admin.users'))->assertOk()->assertSee($owner->email)->assertSee('Users and verification');
        $this->actingAs($admin)->get(route('events.show', $event))->assertOk()->assertSee($event->title);
    }

    public function test_super_admin_can_inspect_another_organisers_workspace_but_cannot_mutate_it(): void
    {
        $owner = $this->user('readonly-owner@example.com');
        $admin = $this->user('readonly-admin@example.com', 'super_admin');
        $event = $this->event($owner, ['title' => 'Read-only inspected event']);

        $this->actingAs($admin)
            ->get(route('events.participants', $event))
            ->assertOk()
            ->assertViewHas('workspaceTab', 'participants')
            ->assertSee($event->title);
        $this->actingAs($admin)->get(route('events.design', $event))->assertOk();

        $this->actingAs($admin)
            ->put(route('events.design.update', $event), ['title' => 'Admin changed title'])
            ->assertForbidden();
        $this->actingAs($admin)
            ->post(route('events.guests.store', $event), [
                'name' => 'Admin-created participant',
                'email' => 'participant@example.com',
                'max_guests' => 1,
            ])
            ->assertForbidden();

        $this->assertSame('Read-only inspected event', $event->fresh()->title);
        $this->assertDatabaseMissing('invitees', ['event_id' => $event->id, 'email' => 'participant@example.com']);
    }

    public function test_only_super_admin_can_access_the_administrative_audit_log(): void
    {
        $admin = $this->user('audit-admin@example.com', 'super_admin');
        $organiser = $this->user('audit-subject@example.com');
        AdminAuditLog::create([
            'actor_id' => $admin->id,
            'subject_user_id' => $organiser->id,
            'auditable_type' => User::class,
            'auditable_id' => $organiser->id,
            'action' => 'user.role_changed',
            'reason' => 'Approved for platform operations coverage.',
            'before_state' => ['role' => 'organiser'],
            'after_state' => ['role' => 'super_admin'],
        ]);

        $this->actingAs($admin)
            ->get(route('admin.audit.index'))
            ->assertOk()
            ->assertSee('Audit log')
            ->assertSee($organiser->email)
            ->assertSee('Approved for platform operations coverage.');

        $this->actingAs($organiser)->get(route('admin.audit.index'))->assertForbidden();
    }

    public function test_event_control_filters_scheduled_ongoing_and_completed_events(): void
    {
        $this->travelTo(Carbon::parse('2026-10-23 12:00:00'));
        $owner = $this->user('filter-owner@example.com');
        $admin = $this->user('filter-admin@example.com', 'super_admin');
        $scheduled = $this->event($owner, [
            'title' => 'Scheduled Summit',
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHours(2),
        ]);
        $ongoing = $this->event($owner, [
            'title' => 'Ongoing Workshop',
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHour(),
        ]);
        $completed = $this->event($owner, [
            'title' => 'Completed Conference',
            'starts_at' => now()->subDays(2),
            'ends_at' => now()->subDay(),
        ]);
        $completedWithoutEnd = $this->event($owner, [
            'title' => 'Completed Meetup',
            'starts_at' => now()->subHour(),
            'ends_at' => null,
        ]);

        $this->actingAs($admin)->get(route('admin.events', ['state' => 'scheduled']))
            ->assertOk()
            ->assertViewHas('events', fn ($events) => collect($events->items())->pluck('id')->all() === [$scheduled->id]);
        $this->actingAs($admin)->get(route('admin.events', ['state' => 'ongoing']))
            ->assertOk()
            ->assertViewHas('events', fn ($events) => collect($events->items())->pluck('id')->all() === [$ongoing->id]);
        $this->actingAs($admin)->get(route('admin.events', ['state' => 'completed']))
            ->assertOk()
            ->assertViewHas('events', function ($events) use ($completed, $completedWithoutEnd): bool {
                return collect($events->items())->pluck('id')->sort()->values()->all()
                    === collect([$completed->id, $completedWithoutEnd->id])->sort()->values()->all();
            });
    }

    public function test_super_admin_control_areas_are_separate_pages(): void
    {
        $admin = $this->user('controls@example.com', 'super_admin');
        $owner = $this->user('event-owner@example.com');
        $event = $this->event($owner);

        $this->actingAs($admin)->get(route('admin.events').'?q=Owner')->assertOk()->assertSee('Event control')->assertSee($event->title);
        $this->actingAs($admin)->get(route('admin.deliveries'))->assertOk()->assertSee('Invitation deliveries');
        $this->actingAs($admin)->get(route('admin.payments'))->assertOk()->assertSee('Participation collections');
        $this->actingAs($admin)->get(route('admin.pledges'))->assertOk()->assertSee('Pledge payment ledger');
        $this->actingAs($admin)->get(route('admin.settlements'))->assertOk()->assertSee('Settlement requests');
    }

    public function test_super_admin_uses_admin_home_and_cannot_create_organiser_events(): void
    {
        $admin = $this->user('admin-home@example.com', 'super_admin');
        $this->actingAs($admin)->get('/dashboard')->assertRedirect(route('admin.index'));
        $this->actingAs($admin)->get('/events/create')->assertForbidden();
        $this->actingAs($admin)->get(route('admin.index'))->assertOk()->assertSee('System Admin')->assertDontSee('My events');
    }

    public function test_super_admin_login_redirects_to_admin_console(): void
    {
        $admin = $this->user('login-admin@example.com', 'super_admin');
        $this->post('/login', ['email' => $admin->email, 'password' => 'passwordlong'])->assertRedirect(route('admin.index'));
    }

    public function test_super_admin_can_manage_roles_but_cannot_demote_self(): void
    {
        $admin = $this->user('admin@example.com', 'super_admin');
        $organiser = $this->user('organiser@example.com');

        $this->actingAs($admin)->put(route('admin.users.role', $organiser), ['role' => 'super_admin', 'reason' => 'Needs platform operations access.'])->assertRedirect()->assertSessionHas('success');
        $this->assertTrue($organiser->fresh()->isSuperAdmin());
        $this->assertDatabaseHas('admin_audit_logs', ['actor_id' => $admin->id, 'subject_user_id' => $organiser->id, 'action' => 'user.role_changed']);
        $this->actingAs($admin)->put(route('admin.users.role', $admin), ['role' => 'organiser', 'reason' => 'Testing self-demotion protection.'])->assertSessionHasErrors('role');
        $this->assertTrue($admin->fresh()->isSuperAdmin());
    }

    public function test_existing_organiser_can_be_promoted_from_console_command(): void
    {
        $user = $this->user('promote@example.com');
        $this->artisan('admin:promote', ['email' => $user->email])->assertSuccessful();
        $this->assertTrue($user->fresh()->isSuperAdmin());
    }

    public function test_super_admin_account_can_be_created_from_concealed_console_prompt(): void
    {
        $this->artisan('admin:create', ['email' => 'new-admin@example.com', '--name' => 'New Admin'])
            ->expectsQuestion('Password', 'Admin@123')
            ->assertSuccessful();
        $user = User::where('email', 'new-admin@example.com')->firstOrFail();
        $this->assertSame('New Admin', $user->name);
        $this->assertTrue($user->isSuperAdmin());
        $this->assertTrue(Hash::check('Admin@123', $user->password));
    }
}
