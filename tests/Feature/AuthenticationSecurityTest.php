<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\OkesheniResetPassword;
use Illuminate\Auth\Events\Attempting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AuthenticationSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_registration_uses_one_email_or_mobile_identifier(): void
    {
        $this->get('/register')
            ->assertOk()
            ->assertSee('name="identifier"', false)
            ->assertDontSee('name="email"', false)
            ->assertDontSee('name="phone"', false);

        $this->post('/register', [
            'name' => 'Connected Organiser',
            'identifier' => '  BOTH@Example.com ',
            'password' => 'passwordlong',
            'password_confirmation' => 'passwordlong',
        ])->assertRedirect('/dashboard');

        $user = User::where('email', 'both@example.com')->firstOrFail();
        $this->assertNull($user->phone);

        $this->post('/logout')->assertRedirect('/');
        $this->post('/login', [
            'identifier' => ' BOTH@EXAMPLE.COM ',
            'password' => 'passwordlong',
        ])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);

    }

    public function test_equivalent_phone_formats_cannot_create_duplicate_accounts(): void
    {
        User::create([
            'name' => 'Existing Mobile User',
            'phone' => '+255712345678',
            'password' => 'passwordlong',
        ]);

        foreach (['0712345678', '712345678', '255712345678', '00255712345678'] as $phone) {
            $this->post('/register', [
                'name' => 'Duplicate Mobile User',
                'identifier' => $phone,
                'password' => 'passwordlong',
                'password_confirmation' => 'passwordlong',
            ])->assertSessionHasErrors('identifier');
        }

        $this->assertDatabaseCount('users', 1);
    }

    public function test_legacy_mixed_case_email_cannot_be_registered_again(): void
    {
        User::create([
            'name' => 'Legacy Email User',
            'email' => 'Legacy@Example.com',
            'password' => 'passwordlong',
        ]);

        $this->post('/register', [
            'name' => 'Duplicate Email User',
            'identifier' => ' legacy@example.com ',
            'password' => 'passwordlong',
            'password_confirmation' => 'passwordlong',
        ])->assertSessionHasErrors([
            'identifier' => 'An account already uses this email address.',
        ]);

        $this->assertDatabaseCount('users', 1);
    }

    public function test_identifier_shape_selects_one_lookup_channel_without_ambiguity(): void
    {
        $emailUser = User::create([
            'name' => 'Email User',
            'email' => '0712345678@example.com',
            'password' => 'email-password',
        ]);
        $phoneUser = User::create([
            'name' => 'Phone User',
            'phone' => '+255712345678',
            'password' => 'phone-password',
        ]);

        $this->post('/login', [
            'identifier' => '0712345678',
            'password' => 'phone-password',
        ])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($phoneUser);

        $this->post('/logout');
        $this->post('/login', [
            'identifier' => '0712345678@example.com',
            'password' => 'email-password',
        ])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($emailUser);
    }

    public function test_unknown_identifier_still_enters_the_guard_attempt_path(): void
    {
        Event::fake([Attempting::class]);

        $this->post('/login', [
            'identifier' => 'missing@example.com',
            'password' => 'wrong-password',
        ])->assertSessionHasErrors([
            'identifier' => 'Email, mobile number, or password is incorrect.',
        ]);

        Event::assertDispatched(Attempting::class);
        $this->assertGuest();
    }

    public function test_login_attempts_are_rate_limited(): void
    {
        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $this->post('/login', [
                'identifier' => 'missing@example.com',
                'password' => 'wrong-password',
            ])->assertRedirect();
        }

        $this->post('/login', [
            'identifier' => 'missing@example.com',
            'password' => 'wrong-password',
        ])->assertTooManyRequests();
    }

    public function test_login_failures_do_not_consume_password_recovery_quota(): void
    {
        Notification::fake();

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->post('/login', [
                'identifier' => 'missing@example.com',
                'password' => 'wrong-password',
            ])->assertRedirect();
        }

        $this->post(route('password.email'), [
            'email' => 'missing@example.com',
        ])->assertRedirect()->assertSessionHas('success');

        Notification::assertNothingSent();
    }

    public function test_password_recovery_normalizes_legacy_email_case_and_whitespace(): void
    {
        Notification::fake();
        $user = User::create([
            'name' => 'Legacy Email User',
            'email' => 'Legacy@Example.com',
            'password' => 'passwordlong',
        ]);

        $this->post(route('password.email'), [
            'email' => '  LEGACY@EXAMPLE.COM  ',
        ])->assertRedirect()->assertSessionHas('success');

        Notification::assertSentTo($user, OkesheniResetPassword::class);
    }

    public function test_phone_only_account_is_not_misrepresented_as_email_recoverable(): void
    {
        Notification::fake();
        User::create([
            'name' => 'Phone Only User',
            'phone' => '+255712345678',
            'password' => 'passwordlong',
        ]);

        $this->get(route('password.request'))
            ->assertOk()
            ->assertSee('Password recovery is currently available by email only.');

        $this->post(route('password.email'), [
            'email' => '0712 345 678',
        ])->assertSessionHasErrors('email');

        Notification::assertNothingSent();
    }

    public function test_mobile_login_migration_refuses_a_data_losing_rollback(): void
    {
        $user = User::create([
            'name' => 'Phone Only User',
            'phone' => '+255712345678',
            'password' => 'passwordlong',
        ]);
        $migration = require database_path('migrations/2026_10_24_000029_add_phone_to_users.php');

        try {
            $migration->down();
            $this->fail('The migration should reject rollback while phone-only users exist.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('phone-only user accounts exist', $exception->getMessage());
        }

        $this->assertTrue(Schema::hasColumn('users', 'phone'));
        $this->assertSame('+255712345678', $user->fresh()->phone);
    }

    public function test_structured_values_are_rejected_without_authentication_errors(): void
    {
        $this->post('/register', [
            'name' => 'Malformed Contact',
            'identifier' => ['not', 'a', 'string'],
            'password' => 'passwordlong',
            'password_confirmation' => 'passwordlong',
        ])->assertSessionHasErrors('identifier');

        $this->post('/login', [
            'identifier' => ['not', 'a', 'string'],
            'password' => 'passwordlong',
        ])->assertSessionHasErrors('identifier');

        $this->post('/login', [
            'identifier' => 'missing@example.com',
            'password' => ['not', 'a', 'string'],
        ])->assertSessionHasErrors('password');

        $this->post(route('password.email'), [
            'email' => ['not', 'a', 'string'],
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_system_admin_can_find_and_identify_a_phone_only_account(): void
    {
        $admin = User::create([
            'name' => 'System Admin',
            'email' => 'admin@example.com',
            'password' => 'passwordlong',
        ]);
        $admin->forceFill(['role' => 'super_admin'])->save();
        User::create([
            'name' => 'Mobile Organiser',
            'phone' => '+255712345678',
            'password' => 'passwordlong',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.users', ['q' => '0712 345 678']))
            ->assertOk()
            ->assertSee('Mobile Organiser')
            ->assertSee('+255712345678');
    }
}
