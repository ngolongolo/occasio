<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

class LocalizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    public function test_english_is_the_default_language(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('<html lang="en">', false)
            ->assertSee('Bring people together.')
            ->assertSeeInOrder(['Joining an event?', 'Bring people together.']);
    }

    public function test_user_can_switch_to_swahili_and_the_choice_persists(): void
    {
        $this->post(route('language.update'), [
            'locale' => 'sw',
            'return_to' => '/register',
        ])->assertRedirect('/register');

        $this->get('/register')
            ->assertOk()
            ->assertSee('<html lang="sw">', false)
            ->assertSee('Fungua akaunti yako')
            ->assertSee('Barua pepe au namba ya simu');

        $this->get('/login')
            ->assertOk()
            ->assertSee('Ingia Okesheni');
    }

    public function test_swahili_is_applied_to_the_authenticated_event_creation_flow(): void
    {
        $user = User::create([
            'name' => 'Mratibu',
            'email' => 'mratibu@example.com',
            'password' => 'passwordlong',
        ]);

        $this->actingAs($user)
            ->withSession(['locale' => 'sw'])
            ->get('/events/create')
            ->assertOk()
            ->assertSee('Tengeneza tukio lako')
            ->assertSee('Mwenyeji au shirika')
            ->assertSee('Gharama ya ushiriki')
            ->assertSee('data-create-step-panel="1"', false)
            ->assertSee('data-create-step-panel="2"', false)
            ->assertSee('data-create-step-panel="3"', false)
            ->assertSee('data-wizard-next', false)
            ->assertSee('Endelea');
    }

    public function test_language_endpoint_rejects_unknown_locales_and_external_redirects(): void
    {
        $this->from('/')->post(route('language.update'), [
            'locale' => 'fr',
            'return_to' => '/',
        ])->assertSessionHasErrors('locale');

        $this->post(route('language.update'), [
            'locale' => 'sw',
            'return_to' => 'https://example.test/phishing',
        ])->assertRedirect('/');
    }

    public function test_user_can_switch_back_to_english(): void
    {
        $this->withSession(['locale' => 'sw'])->post(route('language.update'), [
            'locale' => 'en',
            'return_to' => '/login',
        ])->assertRedirect('/login');

        $this->get('/login')
            ->assertOk()
            ->assertSee('<html lang="en">', false)
            ->assertSee('Sign in to Okesheni');
    }
}
