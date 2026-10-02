<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    public function test_user_can_request_and_use_a_password_reset_link(): void
    {
        Notification::fake();
        $user = User::create([
            'name' => 'Organiser',
            'email' => 'organiser@example.com',
            'password' => 'old-password-123',
        ]);

        $this->get(route('password.request'))->assertOk()->assertSee('Reset your password');
        $this->post(route('password.email'), ['email' => $user->email])
            ->assertRedirect()
            ->assertSessionHas('success');

        $token = null;
        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use (&$token) {
            $token = $notification->token;
            return true;
        });

        $this->get(route('password.reset', ['token' => $token, 'email' => $user->email]))
            ->assertOk()
            ->assertSee('Choose a new password');

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => $user->email,
            'password' => 'new-secure-password',
            'password_confirmation' => 'new-secure-password',
        ])->assertRedirect(route('login'))->assertSessionHas('success');

        $this->assertTrue(Hash::check('new-secure-password', $user->fresh()->password));
    }

    public function test_invalid_reset_token_is_rejected(): void
    {
        $user = User::create([
            'name' => 'Organiser',
            'email' => 'organiser@example.com',
            'password' => 'old-password-123',
        ]);

        $this->post(route('password.update'), [
            'token' => 'invalid-token',
            'email' => $user->email,
            'password' => 'new-secure-password',
            'password_confirmation' => 'new-secure-password',
        ])->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check('old-password-123', $user->fresh()->password));
    }

    public function test_login_page_links_to_password_recovery(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee(route('password.request'), false);
    }
}
