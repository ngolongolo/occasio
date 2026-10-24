@extends('layout')
@section('title', 'Organiser verification · Okesheni')
@section('content')
@php
    $status = $verification?->effectiveStatus() ?? 'unverified';
    $isApproved = $verification?->isApproved() ?? false;
@endphp
<main class="verification-page">
    <header class="verification-hero">
        <div>
            <a href="/dashboard" class="create-back">← Back to your events</a>
            <span class="eyebrow">Organiser trust</span>
            <h1>Verify who is behind your events.</h1>
            <p>Verification is separate from event type. Personal hosts, professional planners and organisations can each verify their identity.</p>
        </div>
        <div class="verification-status-card status-{{ $status }}">
            <small>Current status</small>
            <strong>{{ $isApproved ? $verification->badgeLabel() : ucfirst(str_replace('_', ' ', $status)) }}</strong>
            <span>{{ $isApproved ? 'Eligible to display the Okesheni verification badge.' : ($status === 'pending' ? 'Your information is waiting for review.' : 'No active verification badge is shown to guests.') }}</span>
        </div>
    </header>

    @include('partials.alerts')

    <div class="verification-layout">
        <aside class="verification-guide">
            <span class="eyebrow">What changes</span>
            <h2>Clear trust, not a guarantee.</h2>
            <ul>
                <li><i>1</i><span><strong>Standard organiser</strong>Can run free events and invitation-only paid events with manual collection.</span></li>
                <li><i>2</i><span><strong>Identity verified</strong>Suitable for personal hosts and independent professionals.</span></li>
                <li><i>3</i><span><strong>Organisation verified</strong>For registered businesses and organisations.</span></li>
            </ul>
            <p>Guests see only your public name, contact and verification result. Legal and registration information remains private.</p>
        </aside>

        <section class="verification-form-card">
            <div class="verification-form-heading">
                <div><span class="eyebrow">Verification profile</span><h2>{{ $verification ? 'Review your details' : 'Submit your details' }}</h2></div>
                @if($isApproved)<span class="verification-badge">✓ {{ $verification->badgeLabel() }}</span>@endif
            </div>

            @if($isApproved)
                <div class="verification-approved-note"><strong>Your badge is active.</strong><p>Submitting changed details sends the profile back for review. Existing events remain available, but new public paid events and Bluetick Pay are paused until approval.</p></div>
            @elseif($status === 'rejected' || $status === 'changes_requested')
                <div class="verification-review-note is-error"><strong>{{ $status === 'changes_requested' ? 'Changes are requested.' : 'Changes are needed.' }}</strong><p>{{ $verification->decision_message ?: 'Review your public and legal details, then submit the application again.' }}</p></div>
            @elseif($status === 'suspended' || $status === 'expired')
                <div class="verification-review-note is-error"><strong>Your verification is not active.</strong><p>{{ $verification->decision_message ?: 'You may still run invitation-only paid events with manual collection. Submit current details before publishing a paid event or enabling Bluetick Pay.' }}</p></div>
            @endif

            <form method="post" action="{{ route('organiser.verification.store') }}" class="verification-form">
                @csrf
                <div class="verification-choice full">
                    <span class="field-label">Verification requested</span>
                    <label><input type="radio" name="verification_level" value="identity" @checked(old('verification_level', $verification?->verification_level ?: 'identity') === 'identity')><span><strong>Identity verification</strong><small>For individuals and independent professionals.</small></span></label>
                    <label><input type="radio" name="verification_level" value="business" @checked(old('verification_level', $verification?->verification_level) === 'business')><span><strong>Organisation verification</strong><small>For registered businesses and organisations.</small></span></label>
                </div>
                <div class="full"><label for="organiser-type">How do you organise events?</label><select id="organiser-type" name="organiser_type" required>@foreach(['individual'=>'Individual or personal host','professional'=>'Professional event planner','organisation'=>'Organisation or business'] as $value=>$label)<option value="{{ $value }}" @selected(old('organiser_type', $verification?->organiser_type ?: 'individual') === $value)>{{ $label }}</option>@endforeach</select></div>
                <div class="full"><label for="public-name">Public organiser name</label><input id="public-name" name="public_name" maxlength="180" value="{{ old('public_name', $verification?->public_name ?: auth()->user()->name) }}" required><small>Shown beside your verification badge on guest pages.</small></div>
                <div><label for="public-email">Public support email</label><input id="public-email" type="email" name="public_contact_email" maxlength="255" value="{{ old('public_contact_email', $verification?->public_contact_email ?: auth()->user()->email) }}" required></div>
                <div><label for="public-phone">Public support phone</label><input id="public-phone" type="tel" name="public_contact_phone" maxlength="32" placeholder="+255784311111" value="{{ old('public_contact_phone', $verification?->public_contact_phone) }}"><small>Include the country code.</small></div>
                <div class="full verification-private-divider"><span>Private review information</span><small>Never displayed to guests.</small></div>
                <div class="full"><label for="legal-name">Full legal name</label><input id="legal-name" name="legal_name" maxlength="255" value="{{ old('legal_name', $verification?->legal_name) }}" required></div>
                <div><label for="registration-number">Registration number</label><input id="registration-number" name="registration_number" maxlength="180" value="{{ old('registration_number', $verification?->registration_number) }}"><small>Required for organisation verification.</small></div>
                <div><label for="tax-identifier">Tax identifier <span class="muted">(optional)</span></label><input id="tax-identifier" name="tax_identifier" maxlength="180" value="{{ old('tax_identifier', $verification?->tax_identifier) }}"></div>
                <div class="full verification-consent"><p>By submitting, you confirm that these details are accurate and may be reviewed by Okesheni. Additional evidence may be requested through a secure support channel.</p></div>
                <button type="submit" class="full">{{ $status === 'pending' ? 'Update submitted details' : 'Submit for verification' }} →</button>
            </form>
        </section>
    </div>
</main>
@endsection
