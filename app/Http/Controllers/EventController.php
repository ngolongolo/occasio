<?php

namespace App\Http\Controllers;

use App\Jobs\SendInvitation;
use App\Models\Delivery;
use App\Models\Event;
use App\Services\GuestImporter;
use App\Services\InvitationTemplate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class EventController
{
    private function own(Event $event): void
    {
        abort_unless((int) $event->user_id === (int) auth()->id(), 403);
    }

    public function index()
    {
        return view('events.index', ['events' => auth()->user()->events()->withCount('invitees')->latest()->get()]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'title' => 'required|string|max:180', 'host' => 'required|string|max:180',
            'description' => 'required|string|max:5000', 'starts_at' => 'required|date|after:now',
            'rsvp_deadline' => 'required|date|before_or_equal:starts_at', 'venue' => 'required|string|max:255',
            'dress_code' => 'nullable|string|max:180', 'contact' => 'nullable|string|max:100',
        ]);
        $event = auth()->user()->events()->create($data);
        return redirect()->route('events.show', $event);
    }

    public function show(Event $event)
    {
        $this->own($event);
        return view('events.show', [
            'event' => $event,
            'invitees' => $event->invitees()->with('deliveries')->orderBy('name')->paginate(50),
            'stats' => [
                'total' => $event->invitees()->count(),
                'accepted' => $event->invitees()->where('rsvp_status', 'accepted')->count(),
                'declined' => $event->invitees()->where('rsvp_status', 'declined')->count(),
                'seats' => $event->invitees()->sum('attending_count'),
            ],
        ]);
    }

    public function design(Event $event)
    {
        $this->own($event);
        return view('events.design', ['event' => $event, 'defaultSms' => InvitationTemplate::DEFAULT_SMS]);
    }

    public function updateDesign(Request $request, Event $event)
    {
        $this->own($event);
        $data = $request->validate([
            'primary_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'logo' => ['nullable', 'file', 'mimes:png,jpg,jpeg', 'max:2048'],
            'invitation_card' => ['nullable', 'file', 'mimes:pdf,png', 'max:10240'],
            'sms_template' => ['required', 'string', 'max:1000'],
            'remove_logo' => ['nullable', 'boolean'],
            'remove_invitation_card' => ['nullable', 'boolean'],
        ]);

        if ($request->boolean('remove_logo') && $event->logo_path) {
            Storage::disk('local')->delete($event->logo_path);
            $data['logo_path'] = null;
        }
        if ($request->hasFile('logo')) {
            if ($event->logo_path) Storage::disk('local')->delete($event->logo_path);
            $data['logo_path'] = $request->file('logo')->store("events/{$event->id}/branding", 'local');
        }

        if ($request->boolean('remove_invitation_card') && $event->invitation_card_path) {
            Storage::disk('local')->delete($event->invitation_card_path);
            $data['invitation_card_path'] = $data['invitation_card_name'] = $data['invitation_card_mime'] = null;
        }
        if ($request->hasFile('invitation_card')) {
            if ($event->invitation_card_path) Storage::disk('local')->delete($event->invitation_card_path);
            $card = $request->file('invitation_card');
            $data['invitation_card_path'] = $card->store("events/{$event->id}/cards", 'local');
            $data['invitation_card_name'] = $card->getClientOriginalName();
            $data['invitation_card_mime'] = $card->getMimeType();
        }

        unset($data['logo'], $data['invitation_card'], $data['remove_logo'], $data['remove_invitation_card']);
        $event->update($data);
        return redirect()->route('events.design', $event)->with('success', 'Invitation design saved.');
    }

    public function downloadCard(Event $event)
    {
        $this->own($event);
        abort_unless($event->invitation_card_path && Storage::disk('local')->exists($event->invitation_card_path), 404);
        return Storage::disk('local')->download($event->invitation_card_path, $event->invitation_card_name);
    }

    public function import(Request $request, Event $event, GuestImporter $importer)
    {
        $this->own($event);
        $request->validate(['file' => 'required|file|mimes:xlsx,xls,csv,txt|max:5120']);
        $report = $importer->import($request->file('file'), $event);
        return back()->with('success', "Imported {$report['imported']} guests; skipped {$report['duplicates']} duplicates.")->with('import_errors', $report['errors']);
    }

    public function send(Request $request, Event $event)
    {
        $this->own($event);
        $data = $request->validate([
            'guest_ids' => 'required|array|min:1',
            'guest_ids.*' => 'required|integer|distinct',
            'channels' => 'required|array|min:1',
            'channels.*' => 'required|in:email,sms,whatsapp',
            'consent' => 'accepted',
        ]);
        abort_if($event->rsvp_deadline->endOfDay()->isPast(), 422, 'RSVP deadline has passed.');
        $guests = $event->invitees()->whereKey($data['guest_ids'])->get();
        if ($guests->count() !== count($data['guest_ids'])) {
            throw ValidationException::withMessages(['guest_ids' => 'One or more selected guests do not belong to this event.']);
        }
        $count = 0;
        foreach ($guests as $guest) foreach (array_unique($data['channels']) as $channel) {
            if (($channel === 'email' && ! $guest->email) || ($channel !== 'email' && ! $guest->phone)) continue;
            $delivery = Delivery::firstOrNew(['invitee_id' => $guest->id, 'channel' => $channel]);
            $delivery->fill(['status' => 'queued', 'error' => null, 'provider_id' => null])->save();
            SendInvitation::dispatch($delivery->id);
            $count++;
        }
        return back()->with('success', "Sent {$count} invitation".($count === 1 ? '' : 's').' to the selected guests.');
    }

    public function export(Event $event)
    {
        $this->own($event);
        return response()->streamDownload(function () use ($event) {
            $file = fopen('php://output', 'w');
            fputcsv($file, ['name', 'email', 'phone', 'rsvp', 'attending_count', 'dietary']);
            foreach ($event->invitees()->cursor() as $guest) {
                $row = [$guest->name, $guest->email, $guest->phone, $guest->rsvp_status, $guest->attending_count, $guest->dietary];
                fputcsv($file, array_map(fn ($value) => preg_match('/^[=+@\-]/', (string) $value) ? "'".$value : $value, $row));
            }
            fclose($file);
        }, 'guest-responses.csv', ['Content-Type' => 'text/csv']);
    }
}
