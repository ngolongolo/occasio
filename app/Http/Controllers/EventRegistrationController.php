<?php

namespace App\Http\Controllers;

use App\Models\{Event, Invitee};
use App\Services\CheckoutDisclosure;
use App\Services\EventPricingService;
use App\Services\ParticipationPaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class EventRegistrationController
{
    public function find(Request $request)
    {
        $data = $request->validate(['code' => ['required', 'string', 'size:5', 'regex:/^[A-Za-z0-9]{5}$/']]);
        $event = Event::where('public_code', strtoupper($data['code']))->first();
        if (! $event || ! $event->participation_enabled) throw ValidationException::withMessages(['code' => 'No occasion was found with that code. Check the code and try again.']);
        if ($event->visibility === 'invite_only') return redirect()->route('events.access.show', $event->public_code);
        return redirect($event->registrationUrl());
    }

    public function show(string $token, EventPricingService $pricing)
    {
        $event = $this->eventFromPublicIdentifier($token);
        abort_unless($event->participation_enabled, 404);
        if ($event->visibility === 'invite_only') {
            return redirect()->route('events.access.show', $event->public_code);
        }
        if ($token !== $event->public_code) return redirect($event->registrationUrl());
        $remainingCapacity = $event->remainingCapacity();
        $partyLimit = $event->registrationPartyLimit();
        $packages = $this->packageOptions($event, $pricing);
        $packagesSoldOut = $event->usesPackages() && $packages->every(
            fn ($package) => $package->available_places !== null && $package->available_places < 1,
        );
        return view('events.register', [
            'event' => $event,
            'closed' => $event->responsesClosed(),
            'packages' => $packages,
            'remainingCapacity' => $remainingCapacity,
            'partyLimit' => $remainingCapacity === null ? $partyLimit : min($partyLimit, max(1, $remainingCapacity)),
            'soldOut' => $remainingCapacity === 0 || $packagesSoldOut,
        ]);
    }

    public function card(string $token)
    {
        $event = $this->eventFromPublicIdentifier($token);
        abort_unless($event->participation_enabled, 404);
        abort_if($event->visibility === 'invite_only', 404);
        $path = $event->invitation_card_preview_path ?: $event->invitation_card_path;
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, $event->invitation_card_name ?: 'invitation-card', [
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    public function logo(string $token)
    {
        $event = $this->eventFromPublicIdentifier($token);
        abort_unless($event->participation_enabled && $event->visibility === 'link', 404);
        abort_unless($event->logo_path && Storage::disk('local')->exists($event->logo_path), 404);
        return Storage::disk('local')->response($event->logo_path, 'organisation-logo.'.pathinfo($event->logo_path, PATHINFO_EXTENSION), [
            'Cache-Control' => 'public, max-age=3600', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function store(
        Request $request,
        string $token,
        ParticipationPaymentService $payments,
        CheckoutDisclosure $disclosures,
        EventPricingService $pricing,
    )
    {
        $event = $this->eventFromPublicIdentifier($token);
        abort_unless($event->participation_enabled, 404);
        abort_if($event->visibility === 'invite_only', 404);
        abort_if($event->responsesClosed(), 410, 'Registration is closed because this event has started. Please contact the organiser.');

        $bluetickPayNow = $event->participation_payment_timing === 'pay_now' && $event->participation_collection_method === 'bluetick_pay';
        $manualPayNow = $event->participation_payment_timing === 'pay_now' && $event->participation_collection_method === 'manual';
        $collectPaymentNow = $event->rsvp_confirmation_mode !== 'organizer_approval';
        $responses = (array) $request->input('responses', []);
        if (array_key_exists('whatsapp', $responses)) {
            $responses['whatsapp'] = preg_replace('/[\s()\-]/', '', (string) $responses['whatsapp']) ?: null;
        }
        $isPaid = $event->participation_payment_timing !== 'free';
        $requireCheckoutAcknowledgement = $isPaid
            && ($event->participation_payment_timing === 'pay_at_event' || $collectPaymentNow);
        $phone = preg_replace('/[\s()\-]/', '', (string) $request->input('phone')) ?: null;
        $request->merge([
            'email' => strtolower(trim((string) $request->input('email'))) ?: null,
            'phone' => $phone,
            'payment_msisdn' => $bluetickPayNow && $collectPaymentNow ? ($request->input('payment_msisdn') ?: $phone) : $request->input('payment_msisdn'),
            'responses' => $responses,
            'rsvp_status' => $isPaid ? 'accepted' : $request->input('rsvp_status'),
        ]);
        $partyLimit = $event->registrationPartyLimit();
        $fields=$event->rsvpFields();$rules=[
            'name' => ['required', 'string', 'max:150'],
            'email' => [$fields['email']['enabled']?($fields['email']['required']||!$fields['phone']['enabled']?'required':'nullable'):'nullable', 'email', 'max:180', ...($fields['email']['enabled']&&$fields['phone']['enabled']?['required_without:phone']:[])],
            'phone' => [$fields['phone']['enabled']?(($bluetickPayNow&&$collectPaymentNow)||$fields['phone']['required']||!$fields['email']['enabled']?'required':'nullable'):'nullable', ...($fields['phone']['enabled']&&$fields['email']['enabled']&&!($bluetickPayNow&&$collectPaymentNow)?['required_without:email']:[]), 'regex:/^\+[1-9]\d{7,14}$/'],
            'rsvp_status' => ['required', 'in:accepted,declined'],
            'attending_count' => ['required_if:rsvp_status,accepted', 'nullable', 'integer', 'min:1', 'max:'.$partyLimit],
            'event_package_id' => [$event->usesPackages() ? 'required_if:rsvp_status,accepted' : 'nullable', 'nullable', 'integer'],
            'dietary' => [$fields['dietary']['enabled']&&$fields['dietary']['required']?'required_if:rsvp_status,accepted':'nullable', 'string', 'max:1000'],'responses'=>['nullable','array'],
            'payment_msisdn' => [$bluetickPayNow && $collectPaymentNow ? 'required' : 'nullable', 'string', 'max:30'],
            'participation_payment_reference' => [$manualPayNow && $collectPaymentNow ? 'required' : 'nullable', 'string', 'max:150'],
            'checkout_acknowledged' => $requireCheckoutAcknowledgement ? ['required', 'accepted'] : ['nullable'],
        ];if($fields['whatsapp']['enabled'])$rules['responses.whatsapp']=[$fields['whatsapp']['required']?'required_if:rsvp_status,accepted':'nullable','regex:/^\+[1-9]\d{7,14}$/'];foreach(['organization','job_title','accessibility','notes'] as $field)if($fields[$field]['enabled'])$rules["responses.$field"]=[$fields[$field]['required']?'required_if:rsvp_status,accepted':'nullable','string','max:1000'];$data=$request->validate($rules);

        unset($data['checkout_acknowledged']);

        [$guest, $acknowledgement] = DB::transaction(function () use ($event, $data, $partyLimit, $manualPayNow, $collectPaymentNow, $requireCheckoutAcknowledgement, $disclosures, $pricing, $isPaid) {
            $lockedEvent = Event::whereKey($event->id)->lockForUpdate()->firstOrFail();
            $matches = $lockedEvent->invitees()->where(function ($query) use ($data) {
                if ($data['email']) $query->orWhere('email', $data['email']);
                if ($data['phone']) $query->orWhere('phone', $data['phone']);
            })->lockForUpdate()->get();

            if ($matches->count() > 1) {
                throw ValidationException::withMessages(['email' => 'These contact details belong to different registrations. Please contact the organiser.']);
            }

            $attending = $data['rsvp_status'] === 'accepted' ? (int) ($data['attending_count'] ?? 1) : 0;
            $guest = $matches->first() ?: $lockedEvent->invitees()->make([
                'identity_key' => hash('sha256', $data['email'] ?: $data['phone']),
                'token' => Str::random(64),
                'max_guests' => $partyLimit,
            ]);
            $package = null;
            if ($lockedEvent->usesPackages()) {
                $requestedPackageId = (int) ($data['event_package_id'] ?? 0);
                $sameSavedPackage = $guest->exists
                    && (int) $guest->event_package_id === $requestedPackageId
                    && $guest->hasPackageQuoteSnapshot();
                $package = $sameSavedPackage
                    ? $lockedEvent->packages()->whereKey($requestedPackageId)->first()
                    : $pricing->resolvePackage($lockedEvent, $requestedPackageId);
            }

            $previousAttending = (int) $guest->attending_count;
            $previousPackageId = $guest->event_package_id ? (int) $guest->event_package_id : null;
            $requestedPackageId = $package?->id ? (int) $package->id : null;
            $partySizeChanged = $guest->exists && $guest->rsvp_status === 'accepted' && $previousAttending !== $attending;
            $packageChanged = $guest->exists && $guest->rsvp_status === 'accepted' && $previousPackageId !== $requestedPackageId;
            $selectionChanged = $partySizeChanged || $packageChanged;
            $activePayment = $guest->exists && $guest->participationPayments()
                ->whereIn('status', ['initiated', 'pending'])
                ->lockForUpdate()
                ->exists();
            $manualPaymentPending = $guest->exists
                && $guest->participation_payment_status === 'payment_reported';

            if ($selectionChanged && ($activePayment || $manualPaymentPending)) {
                throw ValidationException::withMessages([
                    $packageChanged ? 'event_package_id' : 'attending_count' => 'Your current payment is still being reviewed. Check its status before changing the package or party size.',
                ]);
            }
            if ($guest->exists && $guest->participation_payment_status === 'paid' && $selectionChanged) {
                throw ValidationException::withMessages([
                    $packageChanged ? 'event_package_id' : 'attending_count' => 'This paid registration is already confirmed. Contact the organiser to change its package or party size.',
                ]);
            }
            if ($selectionChanged) {
                $guest->participationPayments()
                    ->whereIn('status', ['failed', 'request_failed'])
                    ->update(['status' => 'superseded']);
            }
            if (! $isPaid && $data['rsvp_status'] === 'accepted' && $lockedEvent->capacity) {
                $reserved = (int) $lockedEvent->invitees()->reservingCapacity()->when($guest->exists, fn ($query) => $query->whereKeyNot($guest->id))->sum('attending_count');
                if ($reserved + $attending > (int) $lockedEvent->capacity) throw ValidationException::withMessages(['attending_count' => 'Only '.max(0, (int) $lockedEvent->capacity - $reserved).' place(s) remain for this event.']);
            }
            $previousConfirmation=$guest->exists&&$guest->rsvp_status==='accepted'&&!($lockedEvent->rsvp_confirmation_mode==='organizer_approval'&&$selectionChanged)?$guest->confirmation_status:null;
            $guest->fill([
                'name' => $data['name'], 'email' => $data['email'], 'phone' => $data['phone'],
                'identity_key' => hash('sha256', $data['email'] ?: $data['phone']),
                'rsvp_status' => $data['rsvp_status'], 'attending_count' => $attending,
                'dietary' => $data['dietary'] ?? null, 'responded_at' => now(),
                'rsvp_answers' => $data['responses'] ?? null,
                'max_guests' => max((int) $guest->max_guests, $partyLimit, 1),
                'participation_payment_status' => $data['rsvp_status'] === 'declined' || $lockedEvent->participation_payment_timing === 'free'
                    ? 'not_required'
                    : ($lockedEvent->participation_payment_timing === 'pay_at_event'
                        ? 'due_at_event'
                        : ($guest->participation_payment_status === 'paid'
                            ? 'paid'
                            : ($activePayment
                                ? $guest->participation_payment_status
                                : ($lockedEvent->participation_collection_method === 'manual' && $collectPaymentNow ? 'payment_reported' : 'payment_required')))),
                'participation_payment_reference' => $manualPayNow && $collectPaymentNow ? $data['participation_payment_reference'] : null,
                'participation_payment_reported_at' => $manualPayNow && $collectPaymentNow ? now() : null,
            ])->save();
            if ($data['rsvp_status'] === 'accepted' && $isPaid) {
                $pricing->assignPackage($guest, $package, $attending);
            }
            $guest->update(['confirmation_status'=>$lockedEvent->confirmationStatusFor($data['rsvp_status'],$guest->participation_payment_status,$previousConfirmation)]);
            $guest->update(['access_pass_issued_at' => $guest->accessPassEligible() ? ($guest->access_pass_issued_at ?? now()) : null]);
            $acknowledgement = $requireCheckoutAcknowledgement && $guest->rsvp_status === 'accepted' && $guest->participation_payment_status !== 'paid'
                ? $disclosures->acknowledge($guest, (int) $guest->attending_count)
                : null;
            return [$guest, $acknowledgement];
        });

        if ($guest->rsvp_status === 'accepted' && $event->participation_payment_timing === 'pay_now') {
            if ($guest->confirmation_status === 'awaiting_approval') {
                return redirect()->route('rsvp.show', $guest->token)
                    ->with('success', 'Your attendance request has been sent to the organiser for approval. No payment has been initiated.');
            }
            if ($event->rsvp_confirmation_mode === 'organizer_approval') {
                return redirect()->route('rsvp.show', $guest->token)
                    ->with('success', 'Your attendance is approved. Review the current total and payment terms to complete registration.');
            }
            if ($bluetickPayNow && $guest->participation_payment_status !== 'paid') {
                $latestPayment = $guest->participationPayments()->latest('id')->first();
                if ($latestPayment?->status !== 'pending') {
                    $payments->initiate($guest, $data['payment_msisdn'], (int) $guest->attending_count, $acknowledgement);
                }
            }
            return redirect()->route('rsvp.payment.show', $guest->token)
                ->with('success', $bluetickPayNow
                    ? 'Attendance saved and payment prompt sent. Complete it on your phone to unlock your QR access pass.'
                    : 'Attendance and payment reference received. The organiser will verify your payment.');
        }
        if ($guest->rsvp_status === 'accepted') {
            return redirect()->route('rsvp.show', $guest->token)
                ->with('success', $event->participation_payment_timing === 'pay_at_event'
                    ? 'Attendance confirmed. Your invitation and QR access pass are ready; payment is due at the event.'
                    : 'Attendance confirmed. Your invitation and QR access pass are ready.');
        }
        return back()->with('success', $guest->wasRecentlyCreated ? 'Registration confirmed. We look forward to welcoming you.' : 'Your attendance response has been updated.');
    }

    private function eventFromPublicIdentifier(string $identifier): Event
    {
        if (preg_match('/^[A-Za-z0-9]{5}$/', $identifier)) {
            return Event::where('public_code', strtoupper($identifier))->firstOrFail();
        }

        return Event::where('registration_token', $identifier)->firstOrFail();
    }

    private function packageOptions(Event $event, EventPricingService $pricing, ?Invitee $except = null)
    {
        if (! $event->usesPackages()) return collect();

        return $pricing->availablePackages($event)->each(function ($package) use ($except): void {
            $package->setAttribute('available_places', $package->remainingCapacity($except));
        });
    }
}
