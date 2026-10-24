<?php

namespace App\Http\Controllers;

use App\Models\OrganiserVerification;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OrganiserVerificationController
{
    public function show(Request $request)
    {
        abort_if($request->user()->isSuperAdmin(), 403);

        return view('organisers.verification', [
            'verification' => $request->user()->organiserVerification,
        ]);
    }

    public function store(Request $request)
    {
        abort_if($request->user()->isSuperAdmin(), 403);

        $data = $request->validate([
            'organiser_type' => ['required', Rule::in(OrganiserVerification::TYPES)],
            'verification_level' => ['required', Rule::in([
                OrganiserVerification::LEVEL_IDENTITY,
                OrganiserVerification::LEVEL_BUSINESS,
            ])],
            'public_name' => ['required', 'string', 'max:180'],
            'public_contact_email' => ['required', 'email', 'max:255'],
            'public_contact_phone' => ['nullable', 'string', 'regex:/^\+[1-9]\d{7,14}$/', 'max:32'],
            'legal_name' => ['required', 'string', 'max:255'],
            'registration_number' => ['nullable', 'required_if:verification_level,business', 'string', 'max:180'],
            'tax_identifier' => ['nullable', 'string', 'max:180'],
        ]);

        if ($data['verification_level'] === OrganiserVerification::LEVEL_BUSINESS
            && $data['organiser_type'] === OrganiserVerification::TYPE_INDIVIDUAL) {
            throw ValidationException::withMessages([
                'organiser_type' => 'Organisation verification requires a professional or organisation profile.',
            ]);
        }

        $request->user()->organiserVerification()->updateOrCreate([], array_merge($data, [
            'status' => OrganiserVerification::STATUS_PENDING,
            'submitted_at' => now(),
            'reviewed_at' => null,
            'reviewed_by' => null,
            'verified_at' => null,
            'expires_at' => null,
            'payout_name_verified_at' => null,
            'review_notes' => null,
            'decision_message' => null,
        ]));

        return redirect()->route('organiser.verification.show')
            ->with('success', 'Your verification details were submitted for review.');
    }

    public function updateByAdmin(Request $request, User $user)
    {
        $data = $request->validate([
            'organiser_type' => ['required', Rule::in(OrganiserVerification::TYPES)],
            'verification_level' => ['required', Rule::in(OrganiserVerification::LEVELS)],
            'status' => ['required', Rule::in(OrganiserVerification::STATUSES)],
            'public_name' => ['nullable', 'string', 'max:180'],
            'public_contact_email' => ['nullable', 'email', 'max:255'],
            'public_contact_phone' => ['nullable', 'string', 'regex:/^\+[1-9]\d{7,14}$/', 'max:32'],
            'expires_at' => ['nullable', 'date', 'after:today'],
            'payout_name_verified' => ['nullable', 'boolean'],
            'review_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        if ($data['status'] === OrganiserVerification::STATUS_APPROVED) {
            if ($data['verification_level'] === OrganiserVerification::LEVEL_NONE) {
                throw ValidationException::withMessages(['verification_level' => 'Choose identity or business verification before approval.']);
            }
            if (empty(trim((string) ($data['public_name'] ?? '')))
                || (empty(trim((string) ($data['public_contact_email'] ?? ''))) && empty(trim((string) ($data['public_contact_phone'] ?? ''))))) {
                throw ValidationException::withMessages(['public_name' => 'Approved organisers need a public name and at least one public contact.']);
            }
        }

        if ($data['status'] === OrganiserVerification::STATUS_UNVERIFIED) {
            $data['verification_level'] = OrganiserVerification::LEVEL_NONE;
        }

        $existing = $user->organiserVerification;
        $approved = $data['status'] === OrganiserVerification::STATUS_APPROVED;
        $pending = $data['status'] === OrganiserVerification::STATUS_PENDING;
        $verification = $user->organiserVerification()->updateOrCreate([], array_merge($data, [
            'reviewed_by' => $pending ? null : $request->user()->id,
            'reviewed_at' => $pending ? null : now(),
            'verified_at' => $approved ? ($existing?->verified_at ?? now()) : null,
            'payout_name_verified_at' => $approved && $request->boolean('payout_name_verified')
                ? ($existing?->payout_name_verified_at ?? now())
                : null,
        ]));

        return back()->with('success', $user->name.' verification is now '.str_replace('_', ' ', $verification->effectiveStatus()).'.');
    }
}
