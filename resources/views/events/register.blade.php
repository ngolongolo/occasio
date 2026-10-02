@extends('layout')
@section('title', 'Register for '.$event->title.' · Okesheni')
@section('content')
<main class="public-registration" style="--event-primary:{{ $event->primary_color ?: '#56283f' }};--event-background:{{ $event->background_color ?: '#fcf9f4' }};--event-text:{{ $event->text_color ?: '#56283f' }}">
    <section class="registration-event">
        <span class="eyebrow">{{ $event->host }}</span><p class="registration-kicker">You are invited</p><h1>{{ $event->title }}</h1><p>{{ $event->description }}</p>
        <div class="registration-facts"><div><small>Date & time</small><strong>{{ $event->starts_at->format('d M Y · g:i A') }} EAT</strong></div><div><small>Venue</small><strong>{{ $event->venue }}</strong></div><div><small>RSVP deadline</small><strong>{{ $event->rsvp_deadline->format('d M Y') }}</strong></div></div>
        @if($event->dress_code)<p><strong>Dress code:</strong> {{ $event->dress_code }}</p>@endif
    </section>
    <section class="panel registration-form"><span class="eyebrow">Attendance registration</span><h2>Confirm your place</h2><p>Already on the guest list? Use the same email address or phone number so we can update your existing response.</p>
        @if($closed)
        <div class="error">
            Registration has closed. Please contact the organiser.
            @if($event->contact)
                {{ $event->contact }}
            @endif
        </div>
        @else
        <form method="post">
            @csrf
            <label for="registration-name">Full name</label><input id="registration-name" name="name" value="{{ old('name') }}" maxlength="150" required autocomplete="name">
            <div class="form-grid"><div><label for="registration-email">Email address</label><input id="registration-email" type="email" name="email" value="{{ old('email') }}" maxlength="180" autocomplete="email"></div><div><label for="registration-phone">Phone number</label><input id="registration-phone" type="tel" name="phone" value="{{ old('phone') }}" placeholder="+255784311111" autocomplete="tel"></div></div><p class="muted">Provide an email address, phone number, or both. Include the country code for phone numbers.</p>
            <label for="registration-status">Will you attend?</label><select id="registration-status" name="rsvp_status" required><option value="accepted" @selected(old('rsvp_status','accepted')==='accepted')>Yes, I will attend</option><option value="declined" @selected(old('rsvp_status')==='declined')>No, I cannot attend</option></select>
            <label for="registration-count">Number attending, including you</label><input id="registration-count" type="number" name="attending_count" min="1" max="10" value="{{ old('attending_count',1) }}">
            <label for="registration-dietary">Dietary requirements or meal preferences</label><textarea id="registration-dietary" name="dietary" maxlength="1000">{{ old('dietary') }}</textarea>
            <button type="submit">Confirm attendance ↗</button>
        </form>
        @endif
        @if($event->contact)<p class="muted">Event contact: {{ $event->contact }}</p>@endif
    </section>
</main>
@endsection
