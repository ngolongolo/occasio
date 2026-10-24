@extends('layout')
@section('title','Verify your invitation · Okesheni')
@section('content')
<main class="invitation-access-shell">
    <section class="invitation-access-intro">
        <a href="/">← Back to Okesheni</a>
        <span class="eyebrow">Private invitation</span>
        <h1>Confirm it’s really you.</h1>
        <p>This occasion is invitation-only. Its title, location, schedule and RSVP details remain private until your invitation is verified.</p>
        <div class="access-code-mark"><small>Occasion code</small><strong>{{ $code }}</strong></div>
        <ul><li>Your details are checked against the organiser’s guest list.</li><li>A one-time code is sent to the matching email or phone.</li><li>The code expires after 10 minutes.</li></ul>
    </section>
    <section class="invitation-access-card">
        @include('partials.alerts')
        @if($challengeToken)
            <span class="eyebrow">Step 2 of 2</span><h2>Enter your verification code</h2><p>Enter the six-digit code sent to the email address or phone number associated with your invitation.</p>
            <form method="post" action="{{ route('events.access.verify',$code) }}">@csrf<label for="invitation-otp">Six-digit code</label><input class="otp-input" id="invitation-otp" name="otp" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" minlength="6" maxlength="6" placeholder="000000" required autofocus><button type="submit">Verify and open invitation →</button></form>
            <form class="access-restart" method="post" action="{{ route('events.access.request',$code) }}">@csrf<label for="access-contact-again">Didn’t receive it? Re-enter your email or phone</label><div><input id="access-contact-again" name="contact" autocomplete="email" required><button type="submit" class="btn secondary">Send another code</button></div></form>
        @else
            <span class="eyebrow">Step 1 of 2</span><h2>Find your invitation</h2><p>Enter the email address or Tanzanian phone number the organiser used when inviting you.</p>
            <form method="post" action="{{ route('events.access.request',$code) }}">@csrf<label for="access-contact">Email address or phone number</label><input id="access-contact" name="contact" value="{{ old('contact') }}" maxlength="180" autocomplete="email" placeholder="you@example.com or 0712345678" required autofocus><small>For your privacy, we won’t reveal whether a particular address or number is on the guest list.</small><button type="submit">Send verification code →</button></form>
        @endif
        <p class="access-help">Need help? Contact the organiser using the channel through which your invitation was sent.</p>
    </section>
</main>
<script>(function(){const otp=document.getElementById('invitation-otp');if(otp)otp.addEventListener('input',function(){otp.value=otp.value.replace(/\D/g,'').slice(0,6);});}());</script>
@endsection
