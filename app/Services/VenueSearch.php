<?php

namespace App\Services;

use App\Exceptions\VenueSearchBusy;
use App\Exceptions\VenueSearchUnavailable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Throwable;

class VenueSearch
{
    /**
     * Run an explicit venue search. This is intentionally not an autocomplete API:
     * the caller should invoke it only after the organiser submits a search.
     *
     * @return array<int, array{label: string, lat: float, lon: float, type: ?string, source: string}>
     */
    public function search(string $query, int $limit = 5): array
    {
        $query = $this->normaliseQuery($query);
        $limit = max(1, min((int) config('services.nominatim.max_results', 5), $limit));
        $cacheKey = 'venue-search:v1:'.sha1(implode('|', [
            (string) config('services.nominatim.url'),
            (string) config('services.nominatim.country_codes'),
            (string) config('services.nominatim.language'),
            $query,
            $limit,
        ]));

        $cached = Cache::get($cacheKey);
        if (is_array($cached)) return $cached;

        $queryLock = Cache::lock($cacheKey.':lock', 10);
        if (! $queryLock->get()) throw new VenueSearchBusy(1);

        try {
            $cached = Cache::get($cacheKey);
            if (is_array($cached)) return $cached;

            $results = $this->requestNominatim($query, $limit);
            Cache::put($cacheKey, $results, max(60, (int) config('services.nominatim.cache_ttl', 86400)));

            return $results;
        } finally {
            $queryLock->release();
        }
    }

    private function requestNominatim(string $query, int $limit): array
    {
        $timeout = max(1, min(15, (int) config('services.nominatim.timeout', 5)));
        $globalLock = Cache::lock('venue-search:nominatim:request', $timeout + 2);
        if (! $globalLock->get()) throw new VenueSearchBusy(1);

        try {
            $rateKey = 'venue-search:nominatim:rate';
            if (RateLimiter::tooManyAttempts($rateKey, 1)) {
                throw new VenueSearchBusy(max(1, RateLimiter::availableIn($rateKey)));
            }

            // Reserve the public endpoint's one-request-per-second allowance before
            // making the request so concurrent workers cannot race each other.
            RateLimiter::hit($rateKey, 1);

            $parameters = [
                'q' => $query,
                'format' => 'jsonv2',
                'addressdetails' => 1,
                'limit' => $limit,
            ];
            if ($countryCodes = trim((string) config('services.nominatim.country_codes'))) {
                $parameters['countrycodes'] = $countryCodes;
            }
            if ($language = trim((string) config('services.nominatim.language'))) {
                $parameters['accept-language'] = $language;
            }
            if ($email = trim((string) config('services.nominatim.contact_email'))) {
                $parameters['email'] = $email;
            }

            $userAgent = trim((string) config('services.nominatim.user_agent'));
            if ($userAgent === '') {
                $userAgent = sprintf('%s/1.0 (%s)', config('app.name', 'Okesheni'), config('app.url'));
            }

            $response = Http::acceptJson()
                ->withHeaders([
                    'User-Agent' => $userAgent,
                    'Referer' => (string) config('app.url'),
                ])
                ->timeout($timeout)
                ->get((string) config('services.nominatim.url'), $parameters);

            if (! $response->successful() || ! is_array($response->json())) {
                throw new VenueSearchUnavailable;
            }

            return collect($response->json())
                ->take($limit)
                ->map(fn ($result) => $this->sanitiseResult($result))
                ->filter()
                ->values()
                ->all();
        } catch (VenueSearchBusy|VenueSearchUnavailable $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new VenueSearchUnavailable;
        } finally {
            $globalLock->release();
        }
    }

    private function sanitiseResult(mixed $result): ?array
    {
        if (! is_array($result) || ! is_numeric($result['lat'] ?? null) || ! is_numeric($result['lon'] ?? null)) return null;

        $latitude = (float) $result['lat'];
        $longitude = (float) $result['lon'];
        if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) return null;

        $label = $this->plainText($result['display_name'] ?? null, 255);
        if ($label === '') return null;

        return [
            'label' => $label,
            'lat' => $latitude,
            'lon' => $longitude,
            'type' => ($type = $this->plainText($result['type'] ?? null, 60)) !== '' ? $type : null,
            'source' => 'nominatim',
        ];
    }

    private function normaliseQuery(string $query): string
    {
        return Str::lower(Str::squish($this->plainText($query, 180)));
    }

    private function plainText(mixed $value, int $limit): string
    {
        if (! is_scalar($value)) return '';

        $value = strip_tags((string) $value);
        $value = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $value) ?? '';

        return Str::limit(Str::squish($value), $limit, '');
    }
}
