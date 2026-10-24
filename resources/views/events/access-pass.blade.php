@extends('layout')
@section('title',$event->title.' · Access pass')
@section('content')
<main class="pass-page">
    <section class="access-pass-card">
        <div class="pass-brand"><div class="pass-cobrand"><img src="/assets/logo/logo_main.png" alt="Okesheni">@if($event->logo_path)<i></i><img class="organisation-logo" src="{{ route('rsvp.logo',$guest->token) }}" alt="{{ $event->host }} logo">@endif</div><span>Verified access pass</span></div>
        <div class="pass-event"><span class="eyebrow">{{ $event->host }}</span><h1>{{ $event->title }}</h1><p>{{ $event->starts_at->format('D, d M Y · g:i A') }} EAT<br>{{ $event->venue }}</p></div>
        <div class="pass-qr"><img src="{{ route('rsvp.pass.qr',$guest->token) }}" alt="QR access pass for {{ $guest->name }}"></div>
        <div class="pass-holder">
            <span>Issued to</span><strong>{{ $guest->name }}</strong>
            @if($guest->package_name_snapshot)<small>{{ $guest->package_name_snapshot }}</small>@endif
            <p><b>{{ $guest->attending_count }}</b> {{ $guest->attending_count===1?'admission':'admissions' }} allowed · <b>{{ $guest->remainingAdmissions() }}</b> remaining</p>
        </div>
        <div class="pass-code">Occasion code <strong>{{ $event->public_code }}</strong></div>
        <p class="pass-guidance">Present this QR code at the entrance. Keep it private—each successful scan records admission against your allowance.</p>
        <div class="pass-actions"><a class="btn" href="{{ route('rsvp.pass.qr',[$guest->token,'download'=>1]) }}">Download QR code</a><button class="btn secondary" type="button" onclick="window.print()">Print pass</button><a href="{{ route('rsvp.show',$guest->token) }}">Back to invitation</a></div>
    </section>
</main>
@endsection
