@extends('admin.layout')
@section('title', 'Participation collections · System Admin')
@section('admin-content')
    <header class="admin-page-head">
        <div><span class="eyebrow">Financial oversight</span><h1>Participation collections</h1><p>Track participant payment requests and provider outcomes. Pledge instalments are maintained in their own ledger.</p></div>
        <span>{{ number_format($payments->total()) }} transactions</span>
    </header>
    @include('partials.alerts')

    @if($paymentSummary->isNotEmpty())
        <section class="admin-metrics" aria-label="Filtered collection totals">
            @foreach($paymentSummary->groupBy('currency') as $currency => $rows)
                @php
                    $paid = $rows->firstWhere('status', 'paid');
                    $pendingCount = $rows->whereIn('status', ['initiated', 'pending'])->sum('payment_count');
                    $failedCount = $rows->whereIn('status', ['failed', 'request_failed'])->sum('payment_count');
                @endphp
                <article>
                    <span>{{ $currency }} confirmed collections</span>
                    <strong>{{ $currency }} {{ number_format((float) ($paid?->total_amount ?? 0), 2) }}</strong>
                    <small>{{ number_format((int) ($paid?->payment_count ?? 0)) }} paid · {{ number_format((int) $pendingCount) }} pending · {{ number_format((int) $failedCount) }} failed</small>
                </article>
            @endforeach
        </section>
    @endif

    <section class="admin-panel">
        <form class="admin-filters" method="get">
            <div><label for="payment-search">Search</label><input id="payment-search" type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Reference, participant, event or organiser"></div>
            <div><label for="payment-status">Status</label><select id="payment-status" name="status"><option value="">All statuses</option>@foreach(['initiated'=>'Initiated','pending'=>'Pending','paid'=>'Paid','failed'=>'Failed','request_failed'=>'Request failed','superseded'=>'Superseded'] as $value=>$label)<option value="{{ $value }}" @selected(($filters['status']??'')===$value)>{{ $label }}</option>@endforeach</select></div>
            <div><label for="payment-currency">Currency</label><select id="payment-currency" name="currency"><option value="">All currencies</option>@foreach($currencies as $currency)<option value="{{ $currency }}" @selected(strtoupper($filters['currency']??'')===$currency)>{{ $currency }}</option>@endforeach</select></div>
            <div><label for="payment-from">From</label><input id="payment-from" type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}"></div>
            <div><label for="payment-to">To</label><input id="payment-to" type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}"></div>
            <button type="submit">Apply filters</button><a class="btn secondary" href="{{ route('admin.payments') }}">Reset</a>
        </form>
        <div class="table-wrap"><table class="admin-table">
            <thead><tr><th>Participant</th><th>Event</th><th>Amount</th><th>References</th><th>Status</th><th>Requested</th><th>Control</th></tr></thead>
            <tbody>@forelse($payments as $payment)
                @php($event=$payment->invitee->event)
                <tr>
                    <td><strong>{{ $payment->invitee->name }}</strong><small>{{ $payment->invitee->email ?: $payment->invitee->phone }}</small></td>
                    <td><strong>{{ $event->title }}</strong><small>{{ $event->user->name }}</small></td>
                    <td><strong>{{ $payment->currency }} {{ number_format((float) $payment->amount,2) }}</strong></td>
                    <td><strong>{{ $payment->reference }}</strong><small>{{ $payment->provider_payment_reference ? 'Provider: '.$payment->provider_payment_reference : 'No provider reference' }}</small></td>
                    <td><span class="admin-role {{ $payment->status==='paid'?'status-accepted':(in_array($payment->status,['failed','request_failed'],true)?'status-declined':'role-organiser') }}">{{ str_replace('_',' ',$payment->status) }}</span></td>
                    <td>{{ ($payment->requested_at ?: $payment->created_at)->format('d M Y') }}<small>{{ ($payment->requested_at ?: $payment->created_at)->format('H:i') }} EAT</small></td>
                    <td><a class="table-control-link" href="{{ route('events.payments',$event) }}">Open event ledger →</a></td>
                </tr>
            @empty<tr><td colspan="7">No participation payments match these filters.</td></tr>@endforelse</tbody>
        </table></div>
        {{ $payments->links('partials.pagination') }}
    </section>
@endsection
