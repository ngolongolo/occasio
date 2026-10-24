<?php

namespace App\Http\Controllers;

use App\Models\{Event, EventAccessChallenge, Invitee};
use App\Services\EventAccessCodeSender;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Hash};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class EventAccessController
{
    public function show(Request $request, string $code)
    {
        $event = $this->privateEvent($code);
        return view('events.access', [
            'code' => $event->public_code,
            'challengeToken' => $request->session()->get('event_access_challenge.'.$event->public_code),
        ]);
    }

    public function requestCode(Request $request, string $code, EventAccessCodeSender $sender)
    {
        $event = $this->privateEvent($code);
        $data = $request->validate(['contact' => ['required', 'string', 'max:180']]);
        $contact = trim($data['contact']);
        $email = filter_var($contact, FILTER_VALIDATE_EMAIL) ? strtolower($contact) : null;
        $phone = $email ? null : $this->normalizePhone($contact);
        $invitee = $event->invitees()->where(function ($query) use ($email, $phone) {
            if ($email) $query->whereRaw('LOWER(email) = ?', [$email]);
            elseif ($phone) $query->where('phone', $phone);
            else $query->whereRaw('1 = 0');
        })->first();

        $challengeToken = Str::random(64);
        if ($invitee) {
            $otp = (string) random_int(100000, 999999);
            $challenge = EventAccessChallenge::create([
                'event_id' => $event->id, 'invitee_id' => $invitee->id, 'token' => $challengeToken,
                'channel' => $email ? 'email' : 'sms', 'destination' => $email ?: $phone,
                'code_hash' => Hash::make($otp), 'expires_at' => now()->addMinutes(10),
            ]);
            try { $sender->send($challenge->load('event'), $otp); }
            catch (\Throwable $exception) { $challenge->update(['delivery_status' => 'failed']); report($exception); }
        }
        $request->session()->put('event_access_challenge.'.$event->public_code, $challengeToken);
        return back()->with('success', 'If those details match an invitation, a six-digit verification code has been sent.');
    }

    public function verify(Request $request, string $code)
    {
        $event = $this->privateEvent($code);
        $data = $request->validate(['otp' => ['required', 'digits:6']]);
        $token = $request->session()->get('event_access_challenge.'.$event->public_code);
        $challenge = $token ? EventAccessChallenge::where('event_id', $event->id)->where('token', $token)->first() : null;
        if (! $challenge || $challenge->verified_at || $challenge->expires_at->isPast() || $challenge->attempts >= 5) {
            throw ValidationException::withMessages(['otp' => 'This verification code is invalid or expired. Request a new code.']);
        }

        $verified = DB::transaction(function () use ($challenge, $data) {
            $locked = EventAccessChallenge::whereKey($challenge->id)->lockForUpdate()->firstOrFail();
            if ($locked->verified_at || $locked->expires_at->isPast() || $locked->attempts >= 5) return false;
            $locked->increment('attempts');
            if (! Hash::check($data['otp'], $locked->code_hash)) return false;
            $locked->update(['verified_at' => now()]);
            return true;
        });
        if (! $verified) throw ValidationException::withMessages(['otp' => 'The verification code is incorrect.']);

        $request->session()->forget('event_access_challenge.'.$event->public_code);
        $request->session()->put('verified_invitation.'.$event->id, $challenge->invitee_id);
        return redirect()->to($challenge->invitee->rsvpUrl())->with('success', 'Invitation verified. You can now view the occasion and respond.');
    }

    private function privateEvent(string $code): Event
    {
        return Event::where('public_code', strtoupper($code))->where('visibility', 'invite_only')->where('participation_enabled', true)->firstOrFail();
    }

    private function normalizePhone(string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', $phone);
        if (str_starts_with($digits, '0')) $digits = '255'.substr($digits, 1);
        return preg_match('/^255\d{9}$/', $digits) ? '+'.$digits : null;
    }
}
