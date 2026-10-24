<?php

namespace App\Http\Controllers;

use App\Models\Invitee;
use App\Services\CheckoutDisclosure;
use App\Services\EventPricingService;
use App\Services\ParticipationPaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class RsvpController
{
    public function show(string $token, EventPricingService $pricing)
    {
        $guest = Invitee::where('token', $token)->with(['event.user.organiserVerification', 'eventPackage', 'pledge', 'latestParticipationPayment'])->firstOrFail();
        $packages = $this->packageOptions($guest, $pricing);

        return view('rsvp', ['guest' => $guest, 'event' => $guest->event, 'packages' => $packages, 'closed' => $guest->event->responsesClosed()]);
    }

    public function card(Request $request, string $token)
    {
        $guest = Invitee::where('token', $token)->with('event')->firstOrFail();
        $event = $guest->event;
        $path = $request->boolean('preview') && $event->invitation_card_preview_path
            ? $event->invitation_card_preview_path
            : $event->invitation_card_path;
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response(
            $path,
            $request->boolean('preview') ? 'invitation-preview.png' : $event->invitation_card_name,
            ['Cache-Control' => 'private, max-age=3600'],
        );
    }

    public function logo(string $token)
    {
        $guest = Invitee::where('token', $token)->with('event')->firstOrFail();
        $path = $guest->event->logo_path;
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, 'organisation-logo.'.pathinfo($path, PATHINFO_EXTENSION), [
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function update(
        Request $request,
        string $token,
        ParticipationPaymentService $payments,
        CheckoutDisclosure $disclosures,
        EventPricingService $pricing,
    ) {
        $guest = Invitee::where('token', $token)->with('event')->firstOrFail();
        $event = $guest->event;
        abort_if($event->responsesClosed(), 410, 'Responses are closed because this event has started. Please contact the organiser.');

        $request->merge(['response_type' => $request->input('response_type', 'participation')]);
        $type = $request->validate(['response_type' => 'required|in:participation,pledge'])['response_type'];

        if ($type === 'participation') {
            abort_unless($event->participation_enabled, 404);
            $manualPayNow = $event->participation_payment_timing === 'pay_now' && $event->participation_collection_method === 'manual';
            $bluetickPayNow = $event->participation_payment_timing === 'pay_now' && $event->participation_collection_method === 'bluetick_pay';
            $isPaid = $event->participation_payment_timing !== 'free';
            if ($isPaid) $request->merge(['rsvp_status' => 'accepted']);
            $requestedCount = max(0, (int) $request->input('attending_count', 0));
            $partySizeChanged = $guest->rsvp_status === 'accepted'
                && $requestedCount > 0
                && $requestedCount !== (int) $guest->attending_count;
            $requestedPackageId = $event->usesPackages() ? (int) $request->input('event_package_id', 0) : null;
            $packageChanged = $event->usesPackages()
                && $guest->rsvp_status === 'accepted'
                && (int) $guest->event_package_id !== $requestedPackageId;
            $approvalPaymentStage = $event->rsvp_confirmation_mode !== 'organizer_approval'
                || ($guest->rsvp_status === 'accepted'
                    && $guest->confirmation_status === 'awaiting_payment'
                    && ! $partySizeChanged
                    && ! $packageChanged);
            $collectPaymentNow = $approvalPaymentStage && $guest->participation_payment_status !== 'paid';
            $requireCheckoutAcknowledgement = $isPaid
                && ($event->participation_payment_timing === 'pay_at_event' || $collectPaymentNow);

            $responses = (array) $request->input('responses', []);
            if (array_key_exists('whatsapp', $responses)) {
                $responses['whatsapp'] = preg_replace('/[\s()\-]/', '', (string) $responses['whatsapp']) ?: null;
            }
            $request->merge(['responses' => $responses]);

            $fields = $event->rsvpFields();
            $rules = [
                'rsvp_status' => 'required|in:accepted,declined',
                'attending_count' => 'required_if:rsvp_status,accepted|nullable|integer|min:1|max:'.$guest->max_guests,
                'event_package_id' => [$event->usesPackages() ? 'required_if:rsvp_status,accepted' : 'nullable', 'nullable', 'integer'],
                'dietary' => [($fields['dietary']['enabled'] && $fields['dietary']['required']) ? 'required_if:rsvp_status,accepted' : 'nullable', 'string', 'max:1000'],
                'responses' => ['nullable', 'array'],
                'participation_payment_reference' => [$manualPayNow && $collectPaymentNow ? 'required_if:rsvp_status,accepted' : 'nullable', 'string', 'max:150'],
                'payment_msisdn' => [$bluetickPayNow && $collectPaymentNow ? 'required_if:rsvp_status,accepted' : 'nullable', 'string', 'max:30'],
                'checkout_acknowledged' => $requireCheckoutAcknowledgement
                    ? ['required_if:rsvp_status,accepted', 'accepted']
                    : ['nullable'],
            ];
            if ($fields['whatsapp']['enabled']) {
                $rules['responses.whatsapp'] = [$fields['whatsapp']['required'] ? 'required_if:rsvp_status,accepted' : 'nullable', 'regex:/^\+[1-9]\d{7,14}$/'];
            }
            foreach (['organization', 'job_title', 'accessibility', 'notes'] as $field) {
                if ($fields[$field]['enabled']) {
                    $rules["responses.$field"] = [$fields[$field]['required'] ? 'required_if:rsvp_status,accepted' : 'nullable', 'string', 'max:1000'];
                }
            }
            $data = $request->validate($rules);

            $paymentMsisdn = $data['payment_msisdn'] ?? null;
            unset($data['payment_msisdn'], $data['checkout_acknowledged']);
            [$guest, $acknowledgement, $savedPaymentStatus] = DB::transaction(function () use ($guest, $data, $pricing, $isPaid, $collectPaymentNow, $requireCheckoutAcknowledgement, $disclosures): array {
                $lockedGuest = Invitee::whereKey($guest->id)->with(['event', 'eventPackage'])->lockForUpdate()->firstOrFail();
                $lockedEvent = $lockedGuest->event()->lockForUpdate()->firstOrFail();
                $lockedGuest->setRelation('event', $lockedEvent);
                $attending = $data['rsvp_status'] === 'accepted' ? (int) ($data['attending_count'] ?? 1) : 0;
                $requestedPackageId = $lockedEvent->usesPackages() ? (int) ($data['event_package_id'] ?? 0) : null;
                $package = null;
                if ($lockedEvent->usesPackages()) {
                    $sameSavedPackage = (int) $lockedGuest->event_package_id === $requestedPackageId
                        && $lockedGuest->hasPackageQuoteSnapshot();
                    $package = $sameSavedPackage
                        ? $lockedEvent->packages()->whereKey($requestedPackageId)->first()
                        : $pricing->resolvePackage($lockedEvent, $requestedPackageId);
                }

                $partySizeChanged = $lockedGuest->rsvp_status === 'accepted'
                    && $attending !== (int) $lockedGuest->attending_count;
                $packageChanged = $lockedEvent->usesPackages()
                    && $lockedGuest->rsvp_status === 'accepted'
                    && (int) $lockedGuest->event_package_id !== (int) $requestedPackageId;
                $selectionChanged = $partySizeChanged || $packageChanged;
                $responseChanged = $data['rsvp_status'] !== $lockedGuest->rsvp_status;
                $activePayment = $lockedGuest->participationPayments()
                    ->whereIn('status', ['initiated', 'pending'])
                    ->lockForUpdate()
                    ->exists();
                $manualPaymentPending = $lockedGuest->participation_payment_status === 'payment_reported';

                if (($activePayment || $manualPaymentPending) && ($selectionChanged || $responseChanged)) {
                    throw ValidationException::withMessages([
                        $packageChanged ? 'event_package_id' : 'attending_count' => 'Your current payment is still being reviewed. Check its status before changing the package or party size.',
                    ]);
                }
                if ($lockedGuest->participation_payment_status === 'paid' && ($selectionChanged || $responseChanged)) {
                    throw ValidationException::withMessages([
                        'rsvp_status' => 'This registration has already been paid. Contact the organiser to change or cancel it.',
                    ]);
                }
                if ($selectionChanged) {
                    $lockedGuest->participationPayments()
                        ->whereIn('status', ['failed', 'request_failed'])
                        ->update(['status' => 'superseded']);
                }

                $changes = $data;
                unset($changes['event_package_id']);
                $changes['rsvp_answers'] = $changes['responses'] ?? $lockedGuest->rsvp_answers;
                unset($changes['responses']);
                $changes['attending_count'] = $attending;
                $changes['responded_at'] = now();

                if ($changes['rsvp_status'] === 'declined' || $lockedEvent->participation_payment_timing === 'free') {
                    $changes['participation_payment_status'] = 'not_required';
                    $changes['participation_payment_reference'] = null;
                    $changes['participation_payment_reported_at'] = null;
                } elseif ($lockedEvent->participation_payment_timing === 'pay_at_event') {
                    $changes['participation_payment_status'] = 'due_at_event';
                    $changes['participation_payment_reference'] = null;
                    $changes['participation_payment_reported_at'] = null;
                } elseif ($lockedGuest->participation_payment_status === 'paid') {
                    $changes['participation_payment_status'] = 'paid';
                } elseif ($activePayment && ! $selectionChanged) {
                    $changes['participation_payment_status'] = $lockedGuest->participation_payment_status;
                } elseif ($lockedEvent->participation_collection_method === 'manual' && $collectPaymentNow) {
                    $changes['participation_payment_status'] = 'payment_reported';
                    $changes['participation_payment_reported_at'] = now();
                } else {
                    $changes['participation_payment_status'] = 'payment_required';
                    $changes['participation_payment_reference'] = null;
                    $changes['participation_payment_reported_at'] = null;
                }

                $currentConfirmation = $lockedGuest->rsvp_status === 'accepted' ? $lockedGuest->confirmation_status : null;
                if ($lockedEvent->rsvp_confirmation_mode === 'organizer_approval' && $selectionChanged) $currentConfirmation = null;
                $changes['confirmation_status'] = $lockedEvent->confirmationStatusFor($changes['rsvp_status'], $changes['participation_payment_status'], $currentConfirmation);
                $lockedGuest->update($changes);

                if ($changes['rsvp_status'] === 'accepted') {
                    if ($isPaid) $pricing->assignPackage($lockedGuest, $package, $attending);
                    else $pricing->assertAvailable($lockedEvent, null, $attending, $lockedGuest);
                }
                $lockedGuest->refresh()->load('event');
                $lockedGuest->update(['access_pass_issued_at' => $lockedGuest->accessPassEligible() ? ($lockedGuest->access_pass_issued_at ?? now()) : null]);

                $acknowledgement = null;
                if ($lockedGuest->rsvp_status === 'accepted' && $requireCheckoutAcknowledgement && $lockedGuest->participation_payment_status !== 'paid') {
                    $acknowledgement = $disclosures->acknowledge($lockedGuest, (int) $lockedGuest->attending_count);
                }

                return [$lockedGuest, $acknowledgement, $changes['participation_payment_status']];
            });

            if ($guest->rsvp_status === 'accepted' && $bluetickPayNow && $guest->participation_payment_status !== 'paid'
                && $guest->confirmation_status !== 'awaiting_approval') {
                $latestPayment = $guest->participationPayments()->latest('id')->first();
                $promptSent = $latestPayment?->status !== 'pending';
                if ($promptSent) {
                    $payments->initiate($guest, (string) $paymentMsisdn, (int) $guest->attending_count, $acknowledgement);
                }

                return redirect()->route('rsvp.payment.show', $guest->token)->with(
                    'success',
                    $promptSent
                        ? 'Attendance saved and payment prompt sent. Complete it on your phone.'
                        : 'Attendance saved. Complete the pending payment prompt on your phone.',
                );
            }

            $message = match (true) {
                $guest->confirmation_status === 'awaiting_approval' => 'Your attendance request has been sent to the organiser for approval. No payment has been initiated.',
                $savedPaymentStatus === 'payment_reported' => 'Your RSVP and payment reference have been received and await organiser verification.',
                $savedPaymentStatus === 'due_at_event' => 'Your place is reserved. Payment is due at the occasion.',
                default => 'Thank you. Your participation response has been saved.',
            };

            return back()->with('success', $message);
        }

        abort_unless($event->pledge_enabled, 404);
        abort_if(in_array($guest->pledge?->status, ['paid', 'overpaid'], true), 422, 'A fully paid pledge cannot be changed. Please contact the organiser.');
        $data = $request->validate([
            'amount' => 'required|numeric|min:1|max:999999999999.99',
            'payment_method' => 'nullable|string|max:100',
            'payment_reference' => 'nullable|string|max:150',
            'message' => 'nullable|string|max:1000',
        ]);
        $status = ! empty($data['payment_reference']) ? 'payment_reported' : 'pledged';
        $guest->pledge()->updateOrCreate([], array_merge($data, ['status' => $status, 'pledged_at' => now(), 'confirmed_at' => null]));

        return back()->with('success', 'Thank you. Your pledge has been recorded. Please use the payment details shown below.');
    }

    private function packageOptions(Invitee $guest, EventPricingService $pricing)
    {
        $event = $guest->event;
        if (! $event->usesPackages()) return collect();

        $packages = $pricing->availablePackages($event);
        if ($guest->eventPackage && ! $packages->contains('id', $guest->eventPackage->id)) {
            $packages->push($guest->eventPackage);
        }

        return $packages
            ->sortBy([['sort_order', 'asc'], ['id', 'asc']])
            ->values()
            ->each(function ($package) use ($guest): void {
                $package->setAttribute('available_places', $package->remainingCapacity($guest));
            });
    }
}
