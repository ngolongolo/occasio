<?php

namespace App\Http\Controllers;

use App\Models\{Event, EventCheckIn, Invitee};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CheckInController
{
    private function own(Event $event): void
    {
        if ((int) $event->user_id === (int) auth()->id()) return;

        abort_unless(auth()->user()->isSuperAdmin(), 403);
        abort_unless(request()->isMethodSafe(), 403, 'System Admin oversight is read-only. Use an explicit admin control for changes.');
    }

    public function scanner(Event $event)
    {
        $this->own($event);
        return view('events.scanner', ['event' => $event, 'recent' => $event->checkIns()->with('invitee')->latest('scanned_at')->limit(10)->get()]);
    }

    public function show(string $token)
    {
        $guest = Invitee::where('access_token', $token)->with(['event', 'checkIns'])->firstOrFail();
        $this->own($guest->event);
        return view('events.check-in', ['guest' => $guest, 'event' => $guest->event]);
    }

    public function store(Request $request, string $token)
    {
        $guest = Invitee::where('access_token', $token)->with('event')->firstOrFail();
        $this->own($guest->event);
        $data = $request->validate(['admitted_count' => ['required', 'integer', 'min:1'], 'collect_at_event' => ['sometimes', 'accepted'], 'note' => ['nullable', 'string', 'max:500']]);

        DB::transaction(function () use ($guest, $data, $request) {
            $locked = Invitee::whereKey($guest->id)->lockForUpdate()->with('event')->firstOrFail();
            if ($locked->rsvp_status !== 'accepted') throw ValidationException::withMessages(['admitted_count' => 'This invitation does not have an accepted RSVP.']);
            if ($locked->event->participation_payment_timing === 'pay_now' && $locked->participation_payment_status !== 'paid') throw ValidationException::withMessages(['admitted_count' => 'Required payment has not been confirmed.']);
            if ($locked->event->participation_payment_timing === 'pay_at_event' && $locked->participation_payment_status !== 'paid' && ! $request->boolean('collect_at_event')) throw ValidationException::withMessages(['collect_at_event' => 'Confirm that payment was collected at the entrance.']);
            $remaining = $locked->remainingAdmissions();
            if ($remaining < 1) throw ValidationException::withMessages(['admitted_count' => 'This invitation has already used all allowed admissions.']);
            if ((int) $data['admitted_count'] > $remaining) throw ValidationException::withMessages(['admitted_count' => "Only {$remaining} admission(s) remain on this invitation."]);
            $newTotal = (int) $locked->attended_count + (int) $data['admitted_count'];
            $locked->update([
                'attendance_status' => 'attended', 'attended_count' => $newTotal,
                'attendance_confirmed_at' => now(), 'attendance_confirmed_by' => auth()->id(),
                'participation_payment_status' => $locked->event->participation_payment_timing === 'pay_at_event' ? 'paid' : $locked->participation_payment_status,
            ]);
            EventCheckIn::create(['event_id' => $locked->event_id, 'invitee_id' => $locked->id, 'operator_id' => auth()->id(), 'admitted_count' => $data['admitted_count'], 'result' => 'admitted', 'note' => $data['note'] ?? null, 'scanned_at' => now()]);
        });
        return redirect()->route('check-in.show', $token)->with('success', 'Admission recorded successfully.');
    }

    public function regenerate(Event $event, Invitee $guest)
    {
        $this->own($event);
        abort_unless((int) $guest->event_id === (int) $event->id, 404);
        $guest->update(['access_token' => Str::random(64), 'access_pass_issued_at' => $guest->accessPassEligible() ? now() : null]);
        return back()->with('success', 'Access pass regenerated. The previous QR code is no longer valid.');
    }
}
