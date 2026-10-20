@extends('layout')
@section('title',$event->title.' · Your invitation')
@section('content')
<main class="rsvp-shell">
    <div class="invitation">
        @if($event->invitation_card_path)
            @if($event->invitation_card_preview_path)<a href="{{ $guest->cardUrl() }}" target="_blank" rel="noopener"><img class="rsvp-card-image" src="{{ $guest->cardUrl(true) }}" alt="{{ $event->title }} invitation card"></a>
            @else<iframe class="rsvp-card-pdf" src="{{ $guest->cardUrl() }}" title="{{ $event->title }} invitation card"></iframe><p><a href="{{ $guest->cardUrl() }}" target="_blank" rel="noopener">Open invitation card ↗</a></p>@endif
        @else
            <span class="heart">♡</span><p class="eyebrow">{{ $event->host }}</p><p>You are cordially invited</p><h2>{{ $event->title }}</h2><p>Dear {{ $guest->name }},</p><p style="white-space:pre-line">{{ $event->description }}</p><div class="invite-date"><strong style="font-size:22px">{{ $event->starts_at->format('d M Y') }}<br>{{ $event->starts_at->format('g:i A') }} EAT</strong></div><strong>{{ $event->venue }}</strong><p>{{ $event->dress_code }}</p><p>Respond by {{ $event->rsvp_deadline->format('d M Y') }}</p><p>{{ $event->contact }}</p>
        @endif
    </div>
    <div class="response-options">
        @include('partials.alerts')
        @if($closed)<section class="panel"><h2>Responses have closed</h2><p>Please contact the organiser: {{ $event->contact }}</p></section>
        @else
            @if($event->participation_enabled)
            <section class="panel"><span class="eyebrow">Participation</span><h2 style="font-size:32px">Will you join us?</h2><p>This invitation is for {{ $guest->name }} and allows up to {{ $guest->max_guests }} {{ $guest->max_guests===1?'person':'people' }} in total.</p><form method="post">@csrf<input type="hidden" name="response_type" value="participation"><label>Your response</label><select name="rsvp_status" required><option value="accepted" @selected(old('rsvp_status',$guest->rsvp_status)==='accepted')>Joyfully accept</option><option value="declined" @selected(old('rsvp_status',$guest->rsvp_status)==='declined')>Regretfully decline</option></select><label>Number attending, including you</label><input type="number" name="attending_count" min="1" max="{{ $guest->max_guests }}" value="{{ old('attending_count',max(1,$guest->attending_count)) }}"><label>Dietary requirements / meal preferences</label><textarea name="dietary" maxlength="1000">{{ old('dietary',$guest->dietary) }}</textarea><button style="margin-top:20px;width:100%">Confirm participation</button></form></section>
            @endif
            @if($event->pledge_enabled)
            <section class="panel pledge-panel"><span class="eyebrow">Pledge</span><h2 style="font-size:32px">Make a pledge</h2>@if($event->pledge_message)<p style="white-space:pre-line">{{ $event->pledge_message }}</p>@endif<form method="post">@csrf<input type="hidden" name="response_type" value="pledge"><label>Pledge amount ({{ $event->pledge_currency }})</label><input type="number" name="amount" min="1" max="999999999999.99" step="0.01" value="{{ old('amount',$guest->pledge?->amount) }}" required><label>Payment method <span class="muted">(optional)</span></label><input name="payment_method" maxlength="100" value="{{ old('payment_method',$guest->pledge?->payment_method) }}" placeholder="Bank transfer or mobile money"><label>Transaction/reference number <span class="muted">(optional)</span></label><input name="payment_reference" maxlength="150" value="{{ old('payment_reference',$guest->pledge?->payment_reference) }}"><label>Message <span class="muted">(optional)</span></label><textarea name="message" maxlength="1000">{{ old('message',$guest->pledge?->message) }}</textarea><button style="margin-top:20px;width:100%">{{ $guest->pledge ? 'Update my pledge' : 'Submit pledge' }}</button></form>@if($guest->pledge)<p><span class="tag">{{ str_replace('_',' ',$guest->pledge->status) }}</span></p>@endif<div class="payment-details"><h3>Payment details</h3><p style="white-space:pre-line">{{ $event->payment_details }}</p><p class="muted">Submitting a pledge does not confirm payment. The organiser will verify receipt.</p></div></section>
            @endif
            <p class="muted">You can update your response using this link until the event starts. Keep your personal invitation link private.</p>
        @endif
    </div>
</main>
@endsection
