<?php

namespace App\Http\Controllers;

use App\Models\Event;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class EventRegistrationController
{
    public function show(string $token)
    {
        $event = Event::where('registration_token', $token)->firstOrFail();
        return view('events.register', ['event' => $event, 'closed' => $event->rsvp_deadline->endOfDay()->isPast()]);
    }

    public function store(Request $request, string $token)
    {
        $event = Event::where('registration_token', $token)->firstOrFail();
        abort_if($event->rsvp_deadline->endOfDay()->isPast(), 410, 'Registration is closed. Please contact the organiser.');

        $request->merge([
            'email' => strtolower(trim((string) $request->input('email'))) ?: null,
            'phone' => preg_replace('/[\s()\-]/', '', (string) $request->input('phone')) ?: null,
        ]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'email' => ['nullable', 'email', 'max:180', 'required_without:phone'],
            'phone' => ['nullable', 'required_without:email', 'regex:/^\+[1-9]\d{7,14}$/'],
            'rsvp_status' => ['required', 'in:accepted,declined'],
            'attending_count' => ['required_if:rsvp_status,accepted', 'nullable', 'integer', 'min:1', 'max:10'],
            'dietary' => ['nullable', 'string', 'max:1000'],
        ]);

        $guest = DB::transaction(function () use ($event, $data) {
            $matches = $event->invitees()->where(function ($query) use ($data) {
                if ($data['email']) $query->orWhere('email', $data['email']);
                if ($data['phone']) $query->orWhere('phone', $data['phone']);
            })->lockForUpdate()->get();

            if ($matches->count() > 1) {
                throw ValidationException::withMessages(['email' => 'These contact details belong to different registrations. Please contact the organiser.']);
            }

            $attending = $data['rsvp_status'] === 'accepted' ? (int) ($data['attending_count'] ?? 1) : 0;
            $guest = $matches->first() ?: $event->invitees()->make([
                'identity_key' => hash('sha256', $data['email'] ?: $data['phone']),
                'token' => Str::random(64),
                'max_guests' => max(1, $attending),
            ]);
            $guest->fill([
                'name' => $data['name'], 'email' => $data['email'], 'phone' => $data['phone'],
                'identity_key' => hash('sha256', $data['email'] ?: $data['phone']),
                'rsvp_status' => $data['rsvp_status'], 'attending_count' => $attending,
                'dietary' => $data['dietary'] ?? null, 'responded_at' => now(),
                'max_guests' => max((int) $guest->max_guests, $attending, 1),
            ])->save();
            return $guest;
        });

        return back()->with('success', $guest->wasRecentlyCreated ? 'Registration confirmed. We look forward to welcoming you.' : 'Your attendance response has been updated.');
    }
}
