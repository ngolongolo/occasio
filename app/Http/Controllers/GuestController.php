<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\Invitee;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class GuestController
{
    public function create(Event $event)
    {
        $this->authorizeEvent($event);
        return view('guests.form', ['event' => $event, 'guest' => new Invitee]);
    }

    public function store(Request $request, Event $event)
    {
        $this->authorizeEvent($event);
        $data = $this->validated($request, $event);
        $data['identity_key'] = $this->identityKey($data);
        $data['token'] = Str::random(64);
        $event->invitees()->create($data);
        return redirect()->route('events.show', $event)->with('success', 'Guest added successfully.');
    }

    public function edit(Event $event, Invitee $guest)
    {
        $this->authorizeGuest($event, $guest);
        return view('guests.form', compact('event', 'guest'));
    }

    public function update(Request $request, Event $event, Invitee $guest)
    {
        $this->authorizeGuest($event, $guest);
        $data = $this->validated($request, $event, $guest);
        $data['identity_key'] = $this->identityKey($data);
        $emailChanged = $guest->email !== $data['email'];
        $phoneChanged = $guest->phone !== $data['phone'];
        $guest->update($data);
        if ($emailChanged) $guest->deliveries()->where('channel', 'email')->delete();
        if ($phoneChanged) $guest->deliveries()->whereIn('channel', ['sms', 'whatsapp'])->delete();
        return redirect()->route('events.show', $event)->with('success', 'Guest updated successfully.');
    }

    public function destroy(Event $event, Invitee $guest)
    {
        $this->authorizeGuest($event, $guest);
        $guest->delete();
        return redirect()->route('events.show', $event)->with('success', 'Guest deleted.');
    }

    private function validated(Request $request, Event $event, ?Invitee $guest = null): array
    {
        $request->merge([
            'email' => strtolower(trim((string) $request->input('email'))) ?: null,
            'phone' => preg_replace('/[\s()\-]/', '', (string) $request->input('phone')) ?: null,
        ]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'email' => ['nullable', 'email', 'max:180', 'required_without:phone'],
            'phone' => ['nullable', 'required_without:email', 'regex:/^\+[1-9]\d{7,14}$/'],
            'max_guests' => ['required', 'integer', 'min:1', 'max:10'],
        ]);
        $duplicate = $event->invitees()->where('identity_key', $this->identityKey($data))
            ->when($guest, fn ($query) => $query->whereKeyNot($guest->getKey()))->exists();
        if ($duplicate) throw ValidationException::withMessages(['email' => 'A guest with these contact details already exists.']);
        return $data;
    }

    private function identityKey(array $data): string
    {
        return hash('sha256', $data['email'] ?: $data['phone']);
    }

    private function authorizeEvent(Event $event): void
    {
        abort_unless((int) $event->user_id === (int) auth()->id(), 403);
    }

    private function authorizeGuest(Event $event, Invitee $guest): void
    {
        $this->authorizeEvent($event);
        abort_unless((int) $guest->event_id === (int) $event->id, 404);
    }
}
