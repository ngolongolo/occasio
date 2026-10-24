<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class VenueSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_venue_search_requires_an_organiser_and_valid_input_before_contacting_the_provider(): void
    {
        Http::fake();

        $this->get(route('venues.search', ['q' => 'Dar es Salaam']))
            ->assertRedirect('/login');

        $systemAdmin = $this->user('map-admin@example.com');
        $systemAdmin->forceFill(['role' => 'super_admin'])->save();
        $this->actingAs($systemAdmin)
            ->getJson(route('venues.search', ['q' => 'Dar es Salaam']))
            ->assertForbidden();

        $organiser = $this->user('map-organiser@example.com');
        $this->actingAs($organiser)
            ->getJson(route('venues.search', ['q' => 'ab']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('q');
        $this->actingAs($organiser)
            ->getJson(route('venues.search', ['q' => 'Dar es Salaam', 'limit' => 6]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('limit');

        Http::assertNothingSent();
    }

    public function test_venue_search_returns_only_safe_bounded_results_with_attribution(): void
    {
        Cache::flush();
        RateLimiter::clear('venue-search:nominatim:rate');
        config([
            'services.nominatim.url' => 'https://nominatim.example.test/search',
            'services.nominatim.user_agent' => 'Okesheni tests (qa@example.com)',
            'services.nominatim.country_codes' => 'tz',
            'services.nominatim.language' => 'en',
        ]);
        Http::fake([
            'nominatim.example.test/*' => Http::response([
                [
                    'place_id' => 101,
                    'display_name' => 'Julius <script>alert(1)</script> Nyerere Airport, Dar es Salaam',
                    'lat' => '-6.8781000',
                    'lon' => '39.2026000',
                    'type' => 'aerodrome',
                ],
                [
                    'place_id' => 102,
                    'display_name' => "Mlimani City\u{0000}, Dar es Salaam",
                    'lat' => '-6.7714000',
                    'lon' => '39.2198000',
                    'type' => 'mall',
                ],
                [
                    'place_id' => 103,
                    'display_name' => 'Invalid coordinates',
                    'lat' => '91',
                    'lon' => '39',
                    'type' => 'venue',
                ],
            ]),
        ]);

        $organiser = $this->user('venue-results@example.com');
        $response = $this->actingAs($organiser)->getJson(route('venues.search', [
            'q' => '  Julius   Nyerere  ',
            'limit' => 3,
        ]));

        $response->assertOk()
            ->assertJsonCount(2, 'results')
            ->assertJsonPath('results.0.label', 'Julius alert(1) Nyerere Airport, Dar es Salaam')
            ->assertJsonPath('results.0.lat', -6.8781)
            ->assertJsonPath('results.0.lon', 39.2026)
            ->assertJsonPath('results.0.source', 'nominatim')
            ->assertJsonPath('results.1.label', 'Mlimani City, Dar es Salaam')
            ->assertJsonPath('attribution.text', '© OpenStreetMap contributors')
            ->assertJsonPath('attribution.url', 'https://www.openstreetmap.org/copyright');
        $this->assertStringNotContainsString('<script>', $response->getContent());

        Http::assertSentCount(1);
        $providerRequest = Http::recorded()->first()[0];
        parse_str((string) parse_url($providerRequest->url(), PHP_URL_QUERY), $query);
        $this->assertSame('https', parse_url($providerRequest->url(), PHP_URL_SCHEME));
        $this->assertSame('nominatim.example.test', parse_url($providerRequest->url(), PHP_URL_HOST));
        $this->assertSame('/search', parse_url($providerRequest->url(), PHP_URL_PATH));
        $this->assertSame('julius nyerere', $query['q'] ?? null);
        $this->assertSame('3', $query['limit'] ?? null);
        $this->assertSame('jsonv2', $query['format'] ?? null);
        $this->assertSame('tz', $query['countrycodes'] ?? null);
        $this->assertContains('Okesheni tests (qa@example.com)', $providerRequest->header('User-Agent'));
    }

    public function test_provider_failure_is_generic_and_manual_entry_remains_available(): void
    {
        Cache::flush();
        RateLimiter::clear('venue-search:nominatim:rate');
        config(['services.nominatim.url' => 'https://nominatim.example.test/search']);
        Http::fake([
            'nominatim.example.test/*' => Http::response('private provider diagnostics', 503),
        ]);

        $organiser = $this->user('venue-failure@example.com');
        $response = $this->actingAs($organiser)->getJson(route('venues.search', [
            'q' => 'Unlisted family garden',
        ]));

        $response->assertStatus(503)->assertExactJson([
            'message' => 'Venue search is temporarily unavailable. You can still type the venue manually.',
        ]);
        $this->assertStringNotContainsString('private provider diagnostics', $response->getContent());
    }

    public function test_normalised_searches_use_the_cache_and_distinct_requests_respect_the_provider_rate_limit(): void
    {
        Cache::flush();
        RateLimiter::clear('venue-search:nominatim:rate');
        config(['services.nominatim.url' => 'https://nominatim.example.test/search']);
        Http::fake([
            'nominatim.example.test/*' => Http::response([[
                'display_name' => 'Mlimani City, Dar es Salaam',
                'lat' => '-6.7714',
                'lon' => '39.2198',
                'type' => 'mall',
            ]]),
        ]);

        $organiser = $this->user('venue-cache@example.com');
        $this->actingAs($organiser)
            ->getJson(route('venues.search', ['q' => 'Mlimani City', 'limit' => 5]))
            ->assertOk()
            ->assertJsonPath('results.0.label', 'Mlimani City, Dar es Salaam');
        $this->actingAs($organiser)
            ->getJson(route('venues.search', ['q' => '  mLiMaNi   CITY  ', 'limit' => 5]))
            ->assertOk()
            ->assertJsonPath('results.0.label', 'Mlimani City, Dar es Salaam');

        Http::assertSentCount(1);

        $this->actingAs($organiser)
            ->getJson(route('venues.search', ['q' => 'Kariakoo Market', 'limit' => 5]))
            ->assertStatus(429)
            ->assertHeader('Retry-After', '1')
            ->assertExactJson([
                'message' => 'Venue search is receiving too many requests. Please try again in a moment.',
            ]);
        Http::assertSentCount(1);
    }

    public function test_event_creation_accepts_free_text_or_a_valid_selected_map_location(): void
    {
        $organiser = $this->user('venue-create@example.com');

        $this->actingAs($organiser)->post('/events', $this->eventPayload(
            'Manual venue event',
            'Asha\'s garden, after the blue gate',
        ))->assertRedirect();

        $manual = Event::where('title', 'Manual venue event')->firstOrFail();
        $this->assertSame("Asha's garden, after the blue gate", $manual->venue);
        $this->assertNull($manual->venue_latitude);
        $this->assertNull($manual->venue_longitude);
        $this->assertNull($manual->venue_source);

        $this->actingAs($organiser)->post('/events', $this->eventPayload(
            'Mapped venue event',
            'Mlimani City, Dar es Salaam',
        ) + [
            'venue_latitude' => '-6.7714000',
            'venue_longitude' => '39.2198000',
            'venue_source' => 'nominatim',
        ])->assertRedirect();

        $mapped = Event::where('title', 'Mapped venue event')->firstOrFail();
        $this->assertSame(-6.7714, $mapped->venue_latitude);
        $this->assertSame(39.2198, $mapped->venue_longitude);
        $this->assertSame('nominatim', $mapped->venue_source);
    }

    public function test_invalid_or_incomplete_map_metadata_cannot_be_saved(): void
    {
        $organiser = $this->user('venue-validation@example.com');
        $payload = $this->eventPayload('Invalid pin event', 'Dar es Salaam');

        $this->actingAs($organiser)->post('/events', $payload + [
            'venue_latitude' => '91',
            'venue_longitude' => '39.2',
            'venue_source' => 'nominatim',
        ])->assertSessionHasErrors('venue_latitude');

        $this->actingAs($organiser)->post('/events', $payload + [
            'venue_latitude' => '-6.8',
        ])->assertSessionHasErrors(['venue_longitude', 'venue_source']);

        $this->actingAs($organiser)->post('/events', $payload + [
            'venue_latitude' => '-6.8',
            'venue_longitude' => '39.2',
            'venue_source' => 'untrusted-provider',
        ])->assertSessionHasErrors('venue_source');

        $this->assertDatabaseMissing('events', ['title' => 'Invalid pin event']);
    }

    public function test_manually_changing_a_venue_clears_its_previous_map_location(): void
    {
        $organiser = $this->user('venue-update@example.com');
        $event = Event::create([
            'user_id' => $organiser->id,
            'title' => 'Garden gathering',
            'host' => 'Asha',
            'description' => 'Welcome',
            'starts_at' => now()->addMonth(),
            'rsvp_deadline' => now()->addWeeks(3),
            'venue' => 'Mlimani City, Dar es Salaam',
            'venue_latitude' => -6.7714,
            'venue_longitude' => 39.2198,
            'venue_source' => 'nominatim',
            'visibility' => 'invite_only',
        ]);

        $this->actingAs($organiser)->put(route('events.design.update', $event), [
            'venue' => 'Asha\'s garden, after the blue gate',
            'primary_color' => '#56283f',
            'background_color' => '#fcf9f4',
            'text_color' => '#30252a',
            'sms_template' => 'Hello {guest_name}. RSVP: {rsvp_url}',
        ])->assertRedirect(route('events.design', $event));

        $event->refresh();
        $this->assertSame("Asha's garden, after the blue gate", $event->venue);
        $this->assertNull($event->venue_latitude);
        $this->assertNull($event->venue_longitude);
        $this->assertNull($event->venue_source);
    }

    public function test_event_forms_expose_an_explicit_accessible_search_without_keystroke_autocomplete(): void
    {
        $organiser = $this->user('venue-form@example.com');
        $create = $this->actingAs($organiser)->get('/events/create');

        $create->assertOk()
            ->assertSee('data-venue-picker', false)
            ->assertSee('data-search-url="'.route('venues.search').'"', false)
            ->assertSee('data-venue-search', false)
            ->assertSee('Search map')
            ->assertSee('role="status" aria-live="polite"', false)
            ->assertSee('aria-label="Venue suggestions"', false)
            ->assertSee('class="venue-picker-attribution"', false)
            ->assertSee('Search results <a href="https://www.openstreetmap.org/copyright"', false)
            ->assertSee('name="venue_latitude"', false)
            ->assertSee('name="venue_longitude"', false)
            ->assertSee('name="venue_source"', false)
            ->assertSee('Manual typing is not sent to OpenStreetMap')
            ->assertSee('keep your venue exactly as typed.')
            ->assertSee('Map data © OpenStreetMap contributors');
        $this->assertMatchesRegularExpression('/data-venue-selection\s+hidden/', $create->getContent());
        $this->assertStringNotContainsString('@hidden', $create->getContent());

        $event = Event::create([
            'user_id' => $organiser->id,
            'title' => 'Existing event',
            'host' => 'Host',
            'description' => 'Welcome',
            'starts_at' => now()->addMonth(),
            'rsvp_deadline' => now()->addWeeks(3),
            'venue' => 'Mwanza',
            'visibility' => 'invite_only',
        ]);
        $this->get(route('events.design', $event))
            ->assertOk()
            ->assertSee('id="settings-venue"', false)
            ->assertSee('data-venue-picker', false);

        $script = file_get_contents(public_path('assets/venue-picker.js'));
        $this->assertStringContainsString('payload.results', $script);
        $this->assertStringContainsString('if (query.length < 3)', $script);
        $this->assertStringContainsString("searchButton.addEventListener('click', search)", $script);
        $this->assertStringContainsString("if (event.key === 'Enter')", $script);
        $this->assertStringNotContainsString("input.addEventListener('input', search)", $script);
        $this->assertStringContainsString("mapCanvas.addEventListener('keydown'", $script);
    }

    private function user(string $email): User
    {
        return User::create([
            'name' => 'Venue Organiser',
            'email' => $email,
            'password' => 'passwordlong',
        ]);
    }

    private function eventPayload(string $title, string $venue): array
    {
        return [
            'title' => $title,
            'host' => 'Venue Host',
            'description' => 'An event with clear directions.',
            'starts_at' => now()->addMonth()->format('Y-m-d H:i:s'),
            'rsvp_deadline' => now()->addWeeks(3)->format('Y-m-d'),
            'venue' => $venue,
        ];
    }
}
