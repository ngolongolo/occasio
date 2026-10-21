@extends('layout')
@section('title', 'Payment monitoring · Super Admin')
@section('content')
<main class="admin-console">
    <header class="admin-page-head"><div><span class="eyebrow">Financial oversight</span><h1>Pledge payments</h1><p>Review recorded and voided pledge installments across all organisers and events.</p></div><span>{{ $payments->total() }} payments</span></header>
    @include('partials.alerts')
    @include('admin.nav')
    <section class="admin-panel">
        <form class="admin-filters" method="get"><div><label for="payment-search">Search</label><input id="payment-search" type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Participant, event or reference"></div><div><label for="payment-status">Status</label><select id="payment-status" name="status"><option value="">All statuses</option><option value="recorded" @selected(($filters['status']??'')==='recorded')>Recorded</option><option value="voided" @selected(($filters['status']??'')==='voided')>Voided</option></select></div><button type="submit">Apply filters</button><a class="btn secondary" href="{{ route('admin.payments') }}">Reset</a></form>
        <div class="table-wrap"><table class="admin-table"><thead><tr><th>Participant</th><th>Event</th><th>Amount</th><th>Payment details</th><th>Status</th><th>Control</th></tr></thead><tbody>@forelse($payments as $payment)@php($event=$payment->pledge->invitee->event)<tr><td><strong>{{ $payment->pledge->invitee->name }}</strong><small>{{ $payment->pledge->invitee->email ?: $payment->pledge->invitee->phone }}</small></td><td><strong>{{ $event->title }}</strong><small>{{ $event->user->name }}</small></td><td><strong>{{ $event->pledge_currency }} {{ number_format($payment->amount,2) }}</strong><small>{{ $payment->payment_date->format('d M Y') }}</small></td><td>{{ $payment->payment_method ?: '—' }}<small>{{ $payment->reference ? 'Ref: '.$payment->reference : 'No reference' }}</small></td><td><span class="admin-role {{ $payment->status==='recorded'?'status-accepted':'role-organiser' }}">{{ $payment->status }}</span>@if($payment->void_reason)<small>{{ $payment->void_reason }}</small>@endif</td><td><a class="table-control-link" href="{{ route('events.pledge-payments.create',[$event,$payment->pledge]) }}">Open ledger →</a></td></tr>@empty<tr><td colspan="6">No pledge payments match these filters.</td></tr>@endforelse</tbody></table></div>
        {{ $payments->links('partials.pagination') }}
    </section>
</main>
@endsection
