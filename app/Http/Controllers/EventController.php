<?php

namespace App\Http\Controllers;

use App\Jobs\SendInvitation;
use App\Models\{Delivery, Event, Invitee, Pledge, PledgePayment};
use App\Services\GuestImporter;
use App\Services\InvitationTemplate;
use App\Services\NotificationDispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
        return $this->workspace($request, $event, 'overview');
    }

    public function participants(Request $request, Event $event) { return $this->workspace($request, $event, 'participants'); }
    public function invitations(Request $request, Event $event) { return $this->workspace($request, $event, 'invitations'); }
    public function attendance(Request $request, Event $event) { abort_unless($event->participation_enabled, 404); return $this->workspace($request, $event, 'attendance'); }
    public function pledges(Request $request, Event $event) { abort_unless($event->pledge_enabled, 404); return $this->workspace($request, $event, 'pledges'); }

    private function workspace(Request $request, Event $event, string $workspaceTab)
    {
        $this->own($event);
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'rsvp' => ['nullable', 'in:pending,accepted,declined'],
            'delivery' => ['nullable', 'in:not_sent,queued,previewed,submitted,failed'],
            'per_page' => ['nullable', 'integer', 'in:10,25,50,100'],
        ]);
        $query = $event->invitees()->with(['deliveries', 'pledge.payments', 'notificationDeliveries'])->orderBy('name');
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
            'workspaceTab' => $workspaceTab,
            'stats' => [
                'total' => $event->invitees()->count(),
                'accepted' => $event->invitees()->where('rsvp_status', 'accepted')->count(),
                'declined' => $event->invitees()->where('rsvp_status', 'declined')->count(),
                'seats' => $event->invitees()->sum('attending_count'),
                'pledged' => $event->pledges()->sum('amount'),
                'confirmed_pledges' => $event->pledges()->where('status', 'paid')->sum('amount'),
                'attended' => $event->invitees()->where('attendance_status', 'attended')->count(),
                'attended_seats' => $event->invitees()->sum('attended_count'),
                'collected' => PledgePayment::whereHas('pledge.invitee', fn ($query) => $query->where('event_id', $event->id))->where('status', 'recorded')->sum('amount'),
                'partially_paid' => $event->pledges()->where('status', 'partially_paid')->count(),
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
            'invitation_card' => ['nullable', 'image', 'mimes:png,jpg,jpeg', 'max:2048'],
            'sms_template' => ['required', 'string', 'max:1000'],
            'participation_enabled' => ['nullable', 'boolean'],
            'pledge_enabled' => ['nullable', 'boolean'],
            'pledge_currency' => ['nullable', 'string', 'size:3', 'required_if:pledge_enabled,1'],
            'pledge_message' => ['nullable', 'string', 'max:2000'],
            'payment_details' => ['nullable', 'string', 'max:5000', 'required_if:pledge_enabled,1'],
            'attendance_thanks_enabled' => ['nullable', 'boolean'],
            'attendance_thanks_mode' => ['nullable', 'in:automatic,manual'],
            'attendance_thanks_email' => ['nullable', 'boolean'],
            'attendance_thanks_sms' => ['nullable', 'boolean'],
            'attendance_email_subject' => ['nullable', 'string', 'max:180'],
            'attendance_email_message' => ['nullable', 'string', 'max:5000'],
            'attendance_sms_message' => ['nullable', 'string', 'max:1000'],
            'pledge_thanks_enabled' => ['nullable', 'boolean'],
            'pledge_thanks_mode' => ['nullable', 'in:automatic,manual'],
            'pledge_thanks_email' => ['nullable', 'boolean'],
            'pledge_thanks_sms' => ['nullable', 'boolean'],
            'pledge_email_subject' => ['nullable', 'string', 'max:180'],
            'pledge_email_message' => ['nullable', 'string', 'max:5000'],
            'pledge_sms_message' => ['nullable', 'string', 'max:1000'],
            'payment_receipt_enabled' => ['nullable', 'boolean'],
            'payment_receipt_mode' => ['nullable', 'in:automatic,manual'],
            'payment_receipt_email' => ['nullable', 'boolean'],
            'payment_receipt_sms' => ['nullable', 'boolean'],
            'payment_receipt_email_subject' => ['nullable', 'string', 'max:180'],
            'payment_receipt_email_message' => ['nullable', 'string', 'max:5000'],
            'payment_receipt_sms_message' => ['nullable', 'string', 'max:1000'],
            'remove_logo' => ['nullable', 'boolean'],
            'remove_invitation_card' => ['nullable', 'boolean'],
        ]);

        $data['participation_enabled'] = $request->has('response_options_present') ? $request->boolean('participation_enabled') : $event->participation_enabled;
        $data['pledge_enabled'] = $request->has('response_options_present') ? $request->boolean('pledge_enabled') : $event->pledge_enabled;
        if (! $data['participation_enabled'] && ! $data['pledge_enabled']) {
            throw ValidationException::withMessages(['participation_enabled' => 'Enable participation, pledges, or both.']);
        }
        $data['pledge_currency'] = strtoupper($data['pledge_currency'] ?? $event->pledge_currency ?? 'TZS');
        $notificationBooleans = ['attendance_thanks_enabled', 'attendance_thanks_email', 'attendance_thanks_sms', 'pledge_thanks_enabled', 'pledge_thanks_email', 'pledge_thanks_sms', 'payment_receipt_enabled', 'payment_receipt_email', 'payment_receipt_sms'];
        if ($request->has('notification_options_present')) {
            foreach ($notificationBooleans as $field) $data[$field] = $request->boolean($field);
            foreach (['attendance', 'pledge'] as $prefix) {
                if ($data[$prefix.'_thanks_enabled'] && ! $data[$prefix.'_thanks_email'] && ! $data[$prefix.'_thanks_sms']) {
                    throw ValidationException::withMessages([$prefix.'_thanks_email' => 'Select email, SMS, or both for enabled notifications.']);
                }
            }
            if ($data['payment_receipt_enabled'] && ! $data['payment_receipt_email'] && ! $data['payment_receipt_sms']) throw ValidationException::withMessages(['payment_receipt_email' => 'Select email, SMS, or both for enabled payment receipts.']);
        } else {
            foreach ($notificationBooleans as $field) $data[$field] = $event->{$field};
            $data['attendance_thanks_mode'] ??= $event->attendance_thanks_mode;
            $data['pledge_thanks_mode'] ??= $event->pledge_thanks_mode;
            $data['payment_receipt_mode'] ??= $event->payment_receipt_mode;
        }

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
            $data['invitation_card_preview_path'] = $data['invitation_card_path'];
        }

        unset($data['logo'], $data['invitation_card'], $data['remove_logo'], $data['remove_invitation_card']);
        $event->update($data);
        return redirect()->route('events.design', $event)->with('success', 'Invitation design saved.');
    }

    public function downloadCard(Event $event)
    {
        $this->own($event);
        abort_unless($event->invitation_card_path && Storage::disk('local')->exists($event->invitation_card_path), 404);
        return Storage::disk('local')->response($event->invitation_card_path, $event->invitation_card_name, [
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
        ]);
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
            fputcsv($file, ['name', 'email', 'phone', 'rsvp', 'attending_count', 'dietary', 'attendance_status', 'attended_count', 'pledge_amount', 'paid_amount', 'outstanding_balance', 'pledge_currency', 'pledge_status', 'payment_count', 'payment_references', 'pledge_message']);
            foreach ($event->invitees()->with('pledge.payments')->cursor() as $guest) {
                $payments = $guest->pledge?->payments->where('status', 'recorded');
                $row = [$guest->name, $guest->email, $guest->phone, $guest->rsvp_status, $guest->attending_count, $guest->dietary, $guest->attendance_status, $guest->attended_count, $guest->pledge?->amount, $guest->pledge?->paid_amount, $guest->pledge?->balance, $guest->pledge ? $event->pledge_currency : null, $guest->pledge?->status, $payments?->count(), $payments?->pluck('reference')->filter()->implode(' | '), $guest->pledge?->message];
                fputcsv($file, array_map(fn ($value) => preg_match('/^[=+@\-]/', (string) $value) ? "'".$value : $value, $row));
            }
            fclose($file);
        }, 'guest-responses.csv', ['Content-Type' => 'text/csv']);
    }

    public function updatePledge(Request $request, Event $event, Pledge $pledge)
    {
        $this->own($event);
        abort_unless((int) $pledge->invitee()->value('event_id') === (int) $event->id, 404);
        $data = $request->validate(['status' => ['required', 'in:pledged,payment_reported,cancelled']]);
        abort_if((float) $pledge->paid_amount > 0, 422, 'A pledge with booked payments has a calculated payment status. Void its payments before changing the pledge status.');
        $pledge->update(['status' => $data['status']]);
        return back()->with('success', 'Pledge status updated.');
    }

    public function createPledgePayment(Event $event, Pledge $pledge)
    {
        $this->own($event);
        $this->authorizePledge($event, $pledge);
        abort_unless($event->pledge_enabled, 404);

        return view('events.pledge-payment-create', [
            'event' => $event,
            'pledge' => $pledge->load(['invitee', 'payments' => fn ($query) => $query->latest('payment_date')->latest('id')]),
        ]);
    }

    public function recordPledgePayment(Request $request, Event $event, Pledge $pledge, NotificationDispatcher $notifications)
    {
        $this->own($event);$this->authorizePledge($event, $pledge);
        abort_if($pledge->status === 'cancelled', 422, 'Restore the pledge before recording a payment.');
        $data = $request->validate(['amount' => ['required', 'numeric', 'min:0.01', 'max:999999999999.99'], 'payment_date' => ['required', 'date', 'before_or_equal:today'], 'payment_method' => ['nullable', 'string', 'max:100'], 'reference' => ['nullable', 'string', 'max:150'], 'note' => ['nullable', 'string', 'max:1000'], 'overpayment_override' => ['nullable', 'boolean'], 'overpayment_reason' => ['nullable', 'string', 'max:1000']]);
        [$payment, $becameFullyPaid] = DB::transaction(function () use ($event, $pledge, $data, $request) {
            $locked = Pledge::whereKey($pledge->id)->lockForUpdate()->firstOrFail();
            if (! empty($data['reference']) && PledgePayment::where('reference', $data['reference'])->whereHas('pledge.invitee', fn ($query) => $query->where('event_id', $event->id))->exists()) throw ValidationException::withMessages(['reference' => 'This payment reference is already recorded for this event.']);
            $remaining = max(0, (float) $locked->amount - (float) $locked->paid_amount);
            $overpayment = (float) $data['amount'] > $remaining + 0.0001;
            if ($overpayment && ! $request->boolean('overpayment_override')) throw ValidationException::withMessages(['amount' => 'The amount exceeds the outstanding balance. Enable the override and provide a reason to continue.']);
            if ($overpayment && empty(trim((string) ($data['overpayment_reason'] ?? '')))) throw ValidationException::withMessages(['overpayment_reason' => 'Explain why this overpayment is being accepted.']);
            $wasFullyPaid = in_array($locked->status, ['paid', 'overpaid'], true);
            $payment = $locked->payments()->create(array_merge($data, ['overpayment_override' => $overpayment, 'recorded_by' => auth()->id(), 'status' => 'recorded']));
            $this->recalculatePledge($locked);
            return [$payment, ! $wasFullyPaid && in_array($locked->fresh()->status, ['paid', 'overpaid'], true)];
        });
        $guest = $pledge->invitee;
        if ($event->payment_receipt_enabled && $event->payment_receipt_mode === 'automatic') $notifications->dispatch($guest, 'pledge_payment_receipt', $this->receiptChannels($event), false, $payment);
        if ($becameFullyPaid && $event->pledge_thanks_enabled && $event->pledge_thanks_mode === 'automatic') $notifications->dispatch($guest, 'pledge_fully_paid_thanks', $this->notificationChannels($event, 'pledge'));
        return redirect()->route('events.pledge-payments.create', [$event, $pledge])->with('success', 'Payment booked. Outstanding balance: '.$event->pledge_currency.' '.number_format(max(0, (float) $pledge->fresh()->balance), 2).'.');
    }

    public function voidPledgePayment(Request $request, Event $event, Pledge $pledge, PledgePayment $payment)
    {
        $this->own($event);$this->authorizePledge($event, $pledge);abort_unless((int) $payment->pledge_id === (int) $pledge->id, 404);
        $data = $request->validate(['void_reason' => ['required', 'string', 'max:1000']]);
        abort_if($payment->status === 'voided', 422, 'This payment is already voided.');
        DB::transaction(function () use ($pledge, $payment, $data) {$payment->update(['status' => 'voided', 'voided_at' => now(), 'voided_by' => auth()->id(), 'void_reason' => $data['void_reason']]);$this->recalculatePledge($pledge);});
        return back()->with('success', 'Payment voided and pledge balance recalculated.');
    }

    public function sendPaymentReceipt(Request $request, Event $event, Pledge $pledge, PledgePayment $payment, NotificationDispatcher $notifications)
    {
        $this->own($event);$this->authorizePledge($event, $pledge);abort_unless((int) $payment->pledge_id === (int) $pledge->id && $payment->status === 'recorded', 404);abort_unless($event->payment_receipt_enabled, 422, 'Enable payment receipts in invitation settings before sending.');
        $count = $notifications->dispatch($pledge->invitee, 'pledge_payment_receipt', $this->receiptChannels($event), $request->boolean('resend'), $payment);
        return back()->with('success', $count ? "Queued {$count} payment receipt".($count === 1 ? '.' : 's.') : 'This payment receipt was already queued or sent.');
    }

    public function updateAttendance(Request $request, Event $event, Invitee $guest, NotificationDispatcher $notifications)
    {
        $this->own($event);
        abort_unless((int) $guest->event_id === (int) $event->id, 404);
        if (! $event->responsesClosed()) throw ValidationException::withMessages(['attendance_status' => 'Attendance can be confirmed after the event starts.']);
        $data = $request->validate(['attendance_status' => ['required', 'in:pending,attended,absent'], 'attended_count' => ['nullable', 'integer', 'min:0', 'max:'.$guest->max_guests]]);
        $attendedCount = $data['attendance_status'] === 'attended' ? max(1, (int) ($data['attended_count'] ?? 1)) : 0;
        $becameAttended = $data['attendance_status'] === 'attended' && $guest->attendance_status !== 'attended';
        $guest->update(['attendance_status' => $data['attendance_status'], 'attended_count' => $attendedCount, 'attendance_confirmed_at' => $data['attendance_status'] === 'pending' ? null : now(), 'attendance_confirmed_by' => $data['attendance_status'] === 'pending' ? null : auth()->id()]);
        if ($becameAttended && $event->attendance_thanks_enabled && $event->attendance_thanks_mode === 'automatic') {
            $notifications->dispatch($guest, 'attendance_thanks', $this->notificationChannels($event, 'attendance'));
        }
        return back()->with('success', 'Attendance updated.');
    }

    public function sendThanks(Request $request, Event $event, Invitee $guest, NotificationDispatcher $notifications)
    {
        $this->own($event);
        abort_unless((int) $guest->event_id === (int) $event->id, 404);
        $type = $request->validate(['type' => ['required', 'in:attendance_thanks,pledge_fully_paid_thanks'], 'resend' => ['nullable', 'boolean']])['type'];
        if ($type === 'attendance_thanks') abort_unless($guest->attendance_status === 'attended', 422, 'Confirm attendance before sending thanks.');
        if ($type === 'pledge_fully_paid_thanks') abort_unless(in_array($guest->pledge?->status, ['paid','overpaid'], true), 422, 'The pledge must be fully paid before sending thanks.');
        $prefix = $type === 'attendance_thanks' ? 'attendance' : 'pledge';
        abort_unless($event->{$prefix.'_thanks_enabled'}, 422, 'Enable this notification in the invitation settings before sending it.');
        $count = $notifications->dispatch($guest, $type, $this->notificationChannels($event, $prefix), $request->boolean('resend'));
        return back()->with('success', $count ? "Queued {$count} thank-you notification".($count === 1 ? '.' : 's.') : 'Thank-you notification was already queued or sent.');
    }

    public function bulkUpdateAttendance(Request $request, Event $event, NotificationDispatcher $notifications)
    {
        $this->own($event);
        if (! $event->responsesClosed()) throw ValidationException::withMessages(['attendance_status' => 'Attendance can be confirmed after the event starts.']);
        $data = $request->validate(['guest_ids' => ['required', 'array', 'min:1'], 'guest_ids.*' => ['integer', 'distinct'], 'attendance_status' => ['required', 'in:pending,attended,absent'], 'attended_count' => ['nullable', 'integer', 'min:0', 'max:10']]);
        $guests = $event->invitees()->whereKey($data['guest_ids'])->get();
        if ($guests->count() !== count($data['guest_ids'])) throw ValidationException::withMessages(['guest_ids' => 'One or more selected participants do not belong to this event.']);
        foreach ($guests as $guest) {
            $becameAttended = $data['attendance_status'] === 'attended' && $guest->attendance_status !== 'attended';
            $count = $data['attendance_status'] === 'attended' ? min($guest->max_guests, max(1, (int) ($data['attended_count'] ?? 1))) : 0;
            $guest->update(['attendance_status' => $data['attendance_status'], 'attended_count' => $count, 'attendance_confirmed_at' => $data['attendance_status'] === 'pending' ? null : now(), 'attendance_confirmed_by' => $data['attendance_status'] === 'pending' ? null : auth()->id()]);
            if ($becameAttended && $event->attendance_thanks_enabled && $event->attendance_thanks_mode === 'automatic') $notifications->dispatch($guest, 'attendance_thanks', $this->notificationChannels($event, 'attendance'));
        }
        return back()->with('success', 'Attendance updated for '.$guests->count().' participant'.($guests->count() === 1 ? '.' : 's.'));
    }

    public function bulkSendThanks(Request $request, Event $event, NotificationDispatcher $notifications)
    {
        $this->own($event);
        $data = $request->validate(['guest_ids' => ['required', 'array', 'min:1'], 'guest_ids.*' => ['integer', 'distinct'], 'type' => ['required', 'in:attendance_thanks,pledge_fully_paid_thanks']]);
        $guests = $event->invitees()->with('pledge')->whereKey($data['guest_ids'])->get();
        if ($guests->count() !== count($data['guest_ids'])) throw ValidationException::withMessages(['guest_ids' => 'One or more selected participants do not belong to this event.']);
        $eligible = $guests->filter(fn ($guest) => $data['type'] === 'attendance_thanks' ? $guest->attendance_status === 'attended' : in_array($guest->pledge?->status, ['paid','overpaid'], true));
        $prefix = $data['type'] === 'attendance_thanks' ? 'attendance' : 'pledge';
        abort_unless($event->{$prefix.'_thanks_enabled'}, 422, 'Enable this notification in the invitation settings before sending it.');
        $count = $eligible->sum(fn ($guest) => $notifications->dispatch($guest, $data['type'], $this->notificationChannels($event, $prefix)));
        return back()->with('success', $count ? "Queued {$count} thank-you notification".($count === 1 ? '.' : 's.') : 'No eligible unsent notifications were found.');
    }

    private function notificationChannels(Event $event, string $prefix): array
    {
        return array_values(array_filter(['email' => $event->{$prefix.'_thanks_email'} ? 'email' : null, 'sms' => $event->{$prefix.'_thanks_sms'} ? 'sms' : null]));
    }

    private function receiptChannels(Event $event): array
    {
        return array_values(array_filter([$event->payment_receipt_email ? 'email' : null, $event->payment_receipt_sms ? 'sms' : null]));
    }

    private function authorizePledge(Event $event, Pledge $pledge): void
    {
        abort_unless((int) $pledge->invitee()->value('event_id') === (int) $event->id, 404);
    }

    private function recalculatePledge(Pledge $pledge): void
    {
        $paid = (float) $pledge->payments()->where('status', 'recorded')->sum('amount');
        $promised = (float) $pledge->amount;
        $status = $paid <= 0 ? ($pledge->payment_reference ? 'payment_reported' : 'pledged') : ($paid < $promised ? 'partially_paid' : ($paid > $promised ? 'overpaid' : 'paid'));
        $pledge->update(['status' => $status, 'paid_at' => $paid >= $promised ? ($pledge->paid_at ?: now()) : null, 'confirmed_at' => $paid >= $promised ? ($pledge->confirmed_at ?: now()) : null, 'payment_confirmed_by' => $paid >= $promised ? auth()->id() : null]);
    }
}
