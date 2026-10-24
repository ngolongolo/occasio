@extends('layout')
@section('title',$guest->name.' · Check in')
@section('content')
@php
    $admissionUnitPrice=(float)($guest->package_unit_price_snapshot??$event->participation_price);
    $admissionCurrency=$guest->package_currency_snapshot?:$event->participation_currency;
@endphp
<main class="checkin-page">
    <a class="event-back" href="{{ route('events.scanner',$event) }}">← Back to scanner</a>
    @include('partials.alerts')
    <section class="checkin-card {{ $guest->remainingAdmissions()<1?'is-used':'' }}">
        <header><span class="eyebrow">{{ $event->title }}</span><div class="checkin-status">{{ $guest->remainingAdmissions()>0?'Valid pass':'Fully used' }}</div><h1>{{ $guest->name }}</h1><p>{{ $guest->email ?: $guest->phone }}</p></header>
        <div class="admission-counts"><div><strong>{{ $guest->attending_count }}</strong><span>Allowed</span></div><div><strong>{{ $guest->attended_count }}</strong><span>Admitted</span></div><div><strong>{{ $guest->remainingAdmissions() }}</strong><span>Remaining</span></div></div>
        <dl class="checkin-details">@if($guest->package_name_snapshot)<div><dt>Package</dt><dd>{{ $guest->package_name_snapshot }}</dd></div>@endif<div><dt>RSVP</dt><dd>{{ ucfirst($guest->rsvp_status) }}</dd></div><div><dt>Payment</dt><dd>{{ str_replace('_',' ',$guest->participation_payment_status) }}</dd></div><div><dt>Occasion code</dt><dd>{{ $event->public_code }}</dd></div></dl>
        @if($guest->accessPassEligible() && $guest->remainingAdmissions()>0)
        <form method="post" action="{{ route('check-in.store',$guest->access_token) }}" class="checkin-form">
            @csrf
            <label for="admitted-count">People entering now</label>
            <input id="admitted-count" type="number" name="admitted_count" min="1" max="{{ $guest->remainingAdmissions() }}" value="{{ old('admitted_count',1) }}" required>
            @if($event->participation_payment_timing==='pay_at_event'&&$guest->participation_payment_status!=='paid')
                <label class="collect-payment">
                    <input type="checkbox" name="collect_at_event" value="1" required>
                    <span>
                        I confirm payment of {{ $admissionCurrency }} {{ number_format($admissionUnitPrice*$guest->attending_count,2) }} was collected
                        @if($guest->package_name_snapshot) for {{ $guest->package_name_snapshot }} @endif
                    </span>
                </label>
            @endif
            <label for="checkin-note">Entrance note <span class="muted">(optional)</span></label>
            <textarea id="checkin-note" name="note" maxlength="500">{{ old('note') }}</textarea>
            <button type="submit">Confirm admission</button>
        </form>
        @elseif($guest->remainingAdmissions()<1)<div class="checkin-complete"><strong>All admissions have been used</strong><p>Do not admit additional people using this pass.</p></div>
        @else<div class="checkin-complete is-warning"><strong>Pass cannot be admitted</strong><p>The RSVP or payment requirement is not confirmed.</p></div>@endif
        @if($guest->checkIns->isNotEmpty())<details class="checkin-history"><summary>Admission history</summary>@foreach($guest->checkIns->sortByDesc('scanned_at') as $entry)<p><strong>+{{ $entry->admitted_count }}</strong> · {{ $entry->scanned_at->format('d M Y, g:i:s A') }}@if($entry->note)<br><small>{{ $entry->note }}</small>@endif</p>@endforeach</details>@endif
    </section>
</main>
@endsection
