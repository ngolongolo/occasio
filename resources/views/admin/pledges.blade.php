@extends('admin.layout')
@section('title', 'Pledge ledger · System Admin')
@section('admin-content')
    <header class="admin-page-head"><div><span class="eyebrow">Financial oversight</span><h1>Pledge payment ledger</h1><p>Review organiser-recorded and voided pledge instalments separately from event participation collections.</p></div><span>{{ number_format($payments->total()) }} payments</span></header>
    @include('partials.alerts')
    <section class="admin-panel">
        <form class="admin-filters" method="get">
            <div><label for="pledge-search">Search</label><input id="pledge-search" type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Participant, event or reference"></div>
            <div><label for="pledge-status">Status</label><select id="pledge-status" name="status"><option value="">All statuses</option><option value="recorded" @selected(($filters['status']??'')==='recorded')>Recorded</option><option value="voided" @selected(($filters['status']??'')==='voided')>Voided</option></select></div>
            <div><label for="pledge-from">From</label><input id="pledge-from" type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}"></div>
            <div><label for="pledge-to">To</label><input id="pledge-to" type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}"></div>
            <button type="submit">Apply filters</button><a class="btn secondary" href="{{ route('admin.pledges') }}">Reset</a>
        </form>
        <div class="table-wrap"><table class="admin-table"><thead><tr><th>Participant</th><th>Event</th><th>Amount</th><th>Payment details</th><th>Status</th><th>Control</th></tr></thead><tbody>@forelse($payments as $payment)@php($event=$payment->pledge->invitee->event)<tr><td><strong>{{ $payment->pledge->invitee->name }}</strong><small>{{ $payment->pledge->invitee->email ?: $payment->pledge->invitee->phone }}</small></td><td><strong>{{ $event->title }}</strong><small>{{ $event->user->name }}</small></td><td><strong>{{ $event->pledge_currency }} {{ number_format((float) $payment->amount,2) }}</strong><small>{{ $payment->payment_date->format('d M Y') }}</small></td><td>{{ $payment->payment_method ?: '—' }}<small>{{ $payment->reference ? 'Ref: '.$payment->reference : 'No reference' }}</small></td><td><span class="admin-role {{ $payment->status==='recorded'?'status-accepted':'role-organiser' }}">{{ $payment->status }}</span>@if($payment->void_reason)<small>{{ $payment->void_reason }}</small>@endif</td><td><a class="table-control-link" href="{{ route('events.pledge-payments.create',[$event,$payment->pledge]) }}">Open ledger →</a></td></tr>@empty<tr><td colspan="6">No pledge payments match these filters.</td></tr>@endforelse</tbody></table></div>
        {{ $payments->links('partials.pagination') }}
    </section>
@endsection
