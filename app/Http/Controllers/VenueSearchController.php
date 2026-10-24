<?php

namespace App\Http\Controllers;

use App\Exceptions\VenueSearchBusy;
use App\Exceptions\VenueSearchUnavailable;
use App\Services\VenueSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VenueSearchController
{
    public function __invoke(Request $request, VenueSearch $venueSearch): JsonResponse
    {
        $data = $request->validate([
            'q' => ['required', 'string', 'min:3', 'max:180'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:5'],
        ]);

        try {
            $results = $venueSearch->search($data['q'], (int) ($data['limit'] ?? 5));
        } catch (VenueSearchBusy $exception) {
            return response()->json([
                'message' => 'Venue search is receiving too many requests. Please try again in a moment.',
            ], 429)->header('Retry-After', (string) $exception->retryAfter);
        } catch (VenueSearchUnavailable) {
            return response()->json([
                'message' => 'Venue search is temporarily unavailable. You can still type the venue manually.',
            ], 503);
        }

        return response()->json([
            'results' => $results,
            'attribution' => [
                'text' => '© OpenStreetMap contributors',
                'url' => 'https://www.openstreetmap.org/copyright',
            ],
        ]);
    }
}
