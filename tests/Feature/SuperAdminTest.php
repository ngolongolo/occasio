<?php

namespace Tests\Feature;

use App\Models\{Event, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    private function event(User $owner): Event
    {
        return Event::create(['user_id' => $owner->id, 'title' => 'Owner Event', 'host' => 'Owner', 'description' => 'Event details', 'starts_at' => now()->addWeek(), 'rsvp_deadline' => now()->addDays(4), 'venue' => 'Mwanza']);
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
        $this->actingAs($admin)->get(route('admin.users'))->assertOk()->assertSee($owner->email)->assertSee('Users and roles');
        $this->actingAs($admin)->get(route('events.show', $event))->assertOk()->assertSee($event->title);
    }

    public function test_super_admin_control_areas_are_separate_pages(): void
    {
        $admin = $this->user('controls@example.com', 'super_admin');
        $owner = $this->user('event-owner@example.com');
        $event = $this->event($owner);

        $this->actingAs($admin)->get(route('admin.events').'?q=Owner')->assertOk()->assertSee('Event control')->assertSee($event->title);
        $this->actingAs($admin)->get(route('admin.deliveries'))->assertOk()->assertSee('Invitation deliveries');
        $this->actingAs($admin)->get(route('admin.payments'))->assertOk()->assertSee('Pledge payments');
    }

    public function test_super_admin_uses_admin_home_and_cannot_create_organiser_events(): void
    {
        $admin = $this->user('admin-home@example.com', 'super_admin');
        $this->actingAs($admin)->get('/dashboard')->assertRedirect(route('admin.index'));
        $this->actingAs($admin)->get('/events/create')->assertForbidden();
        $this->actingAs($admin)->get(route('admin.index'))->assertOk()->assertSee('Super Admin')->assertDontSee('My events');
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

        $this->actingAs($admin)->put(route('admin.users.role', $organiser), ['role' => 'super_admin'])->assertRedirect()->assertSessionHas('success');
        $this->assertTrue($organiser->fresh()->isSuperAdmin());
        $this->actingAs($admin)->put(route('admin.users.role', $admin), ['role' => 'organiser'])->assertSessionHasErrors('role');
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
