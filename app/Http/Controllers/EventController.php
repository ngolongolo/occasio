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
use Symfony\Component\Process\Process;

class EventController
{
    private function own(Event $event): void
    {
        abort_unless((int) $event->user_id === (int) auth()->id(), 403);
    }

    public function index()
    {
        $events = auth()->user()->events()->withCount([
            'invitees',
            'invitees as accepted_count' => fn ($query) => $query->where('rsvp_status', 'accepted'),
            'invitees as pending_count' => fn ($query) => $query->where('rsvp_status', 'pending'),
        ])->orderBy('starts_at')->get();

        return view('events.index', [
            'events' => $events,
            'dashboardStats' => [
                'events' => $events->count(),
                'upcoming' => $events->filter(fn ($event) => ! $event->responsesClosed())->count(),
                'invitees' => $events->sum('invitees_count'),
                'confirmed' => $events->sum('accepted_count'),
            ],
        ]);
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

    public function show(Request $request, Event $event)
    {
        $this->own($event);
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'rsvp' => ['nullable', 'in:pending,accepted,declined'],
            'delivery' => ['nullable', 'in:not_sent,queued,previewed,submitted,failed'],
            'per_page' => ['nullable', 'integer', 'in:10,25,50,100'],
        ]);
        $query = $event->invitees()->with(['deliveries', 'pledge'])->orderBy('name');
        if (! empty($filters['q'])) {
            $search = trim($filters['q']);
            $query->where(function ($query) use ($search) {
                $query->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }
        if (! empty($filters['rsvp'])) $query->where('rsvp_status', $filters['rsvp']);
        if (($filters['delivery'] ?? null) === 'not_sent') {
            $query->doesntHave('deliveries');
        } elseif (! empty($filters['delivery'])) {
            $query->whereHas('deliveries', fn ($delivery) => $delivery->where('status', $filters['delivery']));
        }
        return view('events.show', [
            'event' => $event,
            'invitees' => $query->paginate((int) ($filters['per_page'] ?? 25))->withQueryString(),
            'filters' => $filters,
            'stats' => [
                'total' => $event->invitees()->count(),
                'accepted' => $event->invitees()->where('rsvp_status', 'accepted')->count(),
                'declined' => $event->invitees()->where('rsvp_status', 'declined')->count(),
                'seats' => $event->invitees()->sum('attending_count'),
                'pledged' => $event->pledges()->sum('amount'),
                'confirmed_pledges' => $event->pledges()->where('status', 'confirmed')->sum('amount'),
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
            'background_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'text_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'logo' => ['nullable', 'file', 'mimes:png,jpg,jpeg', 'max:2048'],
            'invitation_card' => ['nullable', 'file', 'mimes:pdf,png', 'max:10240'],
            'sms_template' => ['required', 'string', 'max:1000'],
            'participation_enabled' => ['nullable', 'boolean'],
            'pledge_enabled' => ['nullable', 'boolean'],
            'pledge_currency' => ['nullable', 'string', 'size:3', 'required_if:pledge_enabled,1'],
            'pledge_message' => ['nullable', 'string', 'max:2000'],
            'payment_details' => ['nullable', 'string', 'max:5000', 'required_if:pledge_enabled,1'],
            'remove_logo' => ['nullable', 'boolean'],
            'remove_invitation_card' => ['nullable', 'boolean'],
        ]);

        $data['participation_enabled'] = $request->has('response_options_present') ? $request->boolean('participation_enabled') : $event->participation_enabled;
        $data['pledge_enabled'] = $request->has('response_options_present') ? $request->boolean('pledge_enabled') : $event->pledge_enabled;
        if (! $data['participation_enabled'] && ! $data['pledge_enabled']) {
            throw ValidationException::withMessages(['participation_enabled' => 'Enable participation, pledges, or both.']);
        }
        $data['pledge_currency'] = strtoupper($data['pledge_currency'] ?? $event->pledge_currency ?? 'TZS');

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
            if ($event->invitation_card_preview_path && $event->invitation_card_preview_path !== $event->invitation_card_path) Storage::disk('local')->delete($event->invitation_card_preview_path);
            $data['invitation_card_path'] = $data['invitation_card_preview_path'] = $data['invitation_card_name'] = $data['invitation_card_mime'] = null;
        }
        if ($request->hasFile('invitation_card')) {
            if ($event->invitation_card_path) Storage::disk('local')->delete($event->invitation_card_path);
            if ($event->invitation_card_preview_path && $event->invitation_card_preview_path !== $event->invitation_card_path) Storage::disk('local')->delete($event->invitation_card_preview_path);
            $card = $request->file('invitation_card');
            $data['invitation_card_path'] = $card->store("events/{$event->id}/cards", 'local');
            $data['invitation_card_name'] = $card->getClientOriginalName();
            $data['invitation_card_mime'] = $card->getMimeType();
            $data['invitation_card_preview_path'] = $data['invitation_card_mime'] === 'image/png'
                ? $data['invitation_card_path']
                : $this->createCardPreview($data['invitation_card_path']);
        }

        unset($data['logo'], $data['invitation_card'], $data['remove_logo'], $data['remove_invitation_card']);
        $event->update($data);
        return redirect()->route('events.design', $event)->with('success', 'Invitation design saved.');
    }

    private function createCardPreview(string $cardPath): ?string
    {
        $disk = Storage::disk('local');
        $previewPath = dirname($cardPath).'/'.pathinfo($cardPath, PATHINFO_FILENAME).'-preview.png';
        $outputPrefix = substr($disk->path($previewPath), 0, -4);
        try {
            $process = new Process(['pdftoppm', '-f', '1', '-singlefile', '-png', '-scale-to', '1400', $disk->path($cardPath), $outputPrefix]);
            $process->setTimeout(30);
            $process->run();
            return $process->isSuccessful() && $disk->exists($previewPath) ? $previewPath : null;
        } catch (\Throwable) {
            return null;
        }
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
        if ($event->responsesClosed()) {
            throw ValidationException::withMessages(['guest_ids' => 'Invitations cannot be sent after the event has started.']);
        }
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
            fputcsv($file, ['name', 'email', 'phone', 'rsvp', 'attending_count', 'dietary', 'pledge_amount', 'pledge_currency', 'pledge_status', 'payment_method', 'payment_reference', 'pledge_message']);
            foreach ($event->invitees()->with('pledge')->cursor() as $guest) {
                $row = [$guest->name, $guest->email, $guest->phone, $guest->rsvp_status, $guest->attending_count, $guest->dietary, $guest->pledge?->amount, $guest->pledge ? $event->pledge_currency : null, $guest->pledge?->status, $guest->pledge?->payment_method, $guest->pledge?->payment_reference, $guest->pledge?->message];
                fputcsv($file, array_map(fn ($value) => preg_match('/^[=+@\-]/', (string) $value) ? "'".$value : $value, $row));
            }
            fclose($file);
        }, 'guest-responses.csv', ['Content-Type' => 'text/csv']);
    }

    public function updatePledge(Request $request, Event $event, \App\Models\Pledge $pledge)
    {
        $this->own($event);
        abort_unless((int) $pledge->invitee()->value('event_id') === (int) $event->id, 404);
        $data = $request->validate(['status' => ['required', 'in:pledged,payment_reported,confirmed,cancelled']]);
        $pledge->update(['status' => $data['status'], 'confirmed_at' => $data['status'] === 'confirmed' ? now() : null]);
        return back()->with('success', 'Pledge status updated.');
    }
}
