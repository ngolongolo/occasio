<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TanzanianPhoneNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    public function test_common_tanzanian_mobile_formats_normalize_to_e164(): void
    {
        foreach ([
            '0712 345 678',
            '712345678',
            '255712345678',
            '+255 712 345 678',
            '00255 712 345 678',
        ] as $phone) {
            $this->assertSame('+255712345678', TanzanianPhoneNumber::normalize($phone));
        }

        foreach (['', '+254712345678', '+255512345678', '0712-call-me', '12345'] as $phone) {
            $this->assertNull(TanzanianPhoneNumber::normalize($phone));
        }
    }

    public function test_account_can_be_created_with_email_only(): void
    {
        $response = $this->post('/register', [
            'name' => 'Email Organiser',
            'identifier' => '  ORGANISER@Example.com ',
            'password' => 'passwordlong',
            'password_confirmation' => 'passwordlong',
        ]);

        $response->assertRedirect('/dashboard');
        $user = User::where('email', 'organiser@example.com')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertNull($user->phone);
        $this->assertTrue(Hash::check('passwordlong', $user->password));
    }

    public function test_account_can_be_created_with_phone_only_and_phone_is_normalized(): void
    {
        $response = $this->post('/register', [
            'name' => 'Mobile Organiser',
            'identifier' => '0712 345 678',
            'password' => 'passwordlong',
            'password_confirmation' => 'passwordlong',
        ]);

        $response->assertRedirect('/dashboard');
        $user = User::where('phone', '+255712345678')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertNull($user->email);
    }

    public function test_registration_requires_at_least_one_contact_identifier(): void
    {
        $this->post('/register', [
            'name' => 'No Contact',
            'password' => 'passwordlong',
            'password_confirmation' => 'passwordlong',
        ])->assertSessionHasErrors('identifier');

        $this->assertDatabaseCount('users', 0);
    }

    public function test_registration_rejects_invalid_or_duplicate_normalized_phone(): void
    {
        User::create([
            'name' => 'Existing Organiser',
            'phone' => '+255712345678',
            'password' => 'passwordlong',
        ]);

        $this->post('/register', [
            'name' => 'Duplicate Organiser',
            'identifier' => '255 712 345 678',
            'password' => 'passwordlong',
            'password_confirmation' => 'passwordlong',
        ])->assertSessionHasErrors('identifier');

        $this->post('/register', [
            'name' => 'Invalid Organiser',
            'identifier' => '12345',
            'password' => 'passwordlong',
            'password_confirmation' => 'passwordlong',
        ])->assertSessionHasErrors('identifier');

        $this->assertDatabaseCount('users', 1);
    }

    public function test_registration_accepts_an_eight_character_password_and_rejects_seven(): void
    {
        $this->post('/register', [
            'name' => 'Short Password',
            'identifier' => 'short@example.com',
            'password' => 'Abcd123',
            'password_confirmation' => 'Abcd123',
        ])->assertSessionHasErrors('password');

        $this->post('/register', [
            'name' => 'Valid Password',
            'identifier' => 'valid@example.com',
            'password' => 'Abcd1234',
            'password_confirmation' => 'Abcd1234',
        ])->assertRedirect('/dashboard');

        $this->assertTrue(Hash::check('Abcd1234', User::where('email', 'valid@example.com')->firstOrFail()->password));
    }

    public function test_user_can_login_with_email_or_tanzanian_mobile_number(): void
    {
        $emailUser = User::create([
            'name' => 'Email User',
            'email' => 'email@example.com',
            'password' => 'passwordlong',
        ]);
        $phoneUser = User::create([
            'name' => 'Phone User',
            'phone' => '+255765432109',
            'password' => 'passwordlong',
        ]);

        $this->post('/login', [
            'identifier' => 'EMAIL@EXAMPLE.COM',
            'password' => 'passwordlong',
        ])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($emailUser);

        $this->post('/logout');

        $this->post('/login', [
            'identifier' => '0765-432-109',
            'password' => 'passwordlong',
        ])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($phoneUser);
    }

    public function test_legacy_email_login_field_remains_supported(): void
    {
        $user = User::create([
            'name' => 'Existing User',
            'email' => 'existing@example.com',
            'password' => 'passwordlong',
        ]);

        $this->post('/login', [
            'email' => 'existing@example.com',
            'password' => 'passwordlong',
        ])->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($user);
    }

    public function test_invalid_credentials_use_a_generic_error_and_do_not_authenticate(): void
    {
        User::create([
            'name' => 'Existing User',
            'phone' => '+255712345678',
            'password' => 'passwordlong',
        ]);

        $this->post('/login', [
            'identifier' => '0712345678',
            'password' => 'wrong-password',
        ])->assertSessionHasErrors([
            'identifier' => 'Email, mobile number, or password is incorrect.',
        ]);

        $this->assertGuest();
    }
}
