@extends('admin.layout')
@section('title', 'Settlement monitoring · System Admin')
@section('admin-content')
    <header class="admin-page-head"><div><span class="eyebrow">Financial oversight</span><h1>Settlement requests</h1><p>Monitor organiser withdrawal requests, recipient accounts and payout outcomes across the platform.</p></div><span>{{ number_format($settlements->total()) }} requests</span></header>
    @include('partials.alerts')

    @if($settlementSummary->isNotEmpty())
        <section class="admin-metrics" aria-label="Filtered settlement totals">
            @foreach($settlementSummary->groupBy('currency') as $currency=>$rows)
                @php
                    $paid=$rows->firstWhere('status','paid');
                    $pendingAmount=$rows->whereIn('status',['initiated','pending'])->sum('total_amount');
                    $pendingCount=$rows->whereIn('status',['initiated','pending'])->sum('settlement_count');
                    $failedCount=$rows->whereIn('status',['failed','reversed'])->sum('settlement_count');
                @endphp
                <article><span>{{ $currency }} settlements completed</span><strong>{{ $currency }} {{ number_format((float)($paid?->total_amount??0),2) }}</strong><small>{{ number_format((int)$pendingCount) }} pending ({{ $currency }} {{ number_format((float)$pendingAmount,2) }}) · {{ number_format((int)$failedCount) }} exceptions</small></article>
            @endforeach
        </section>
    @endif

    <section class="admin-panel">
        <form class="admin-filters" method="get">
            <div><label for="settlement-search">Search</label><input id="settlement-search" type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Reference, event, organiser or account"></div>
            <div><label for="settlement-status">Status</label><select id="settlement-status" name="status"><option value="">All statuses</option>@foreach(['initiated'=>'Initiated','pending'=>'Pending','paid'=>'Paid','failed'=>'Failed','reversed'=>'Reversed'] as $value=>$label)<option value="{{ $value }}" @selected(($filters['status']??'')===$value)>{{ $label }}</option>@endforeach</select></div>
            <div><label for="settlement-currency">Currency</label><select id="settlement-currency" name="currency"><option value="">All currencies</option>@foreach($currencies as $currency)<option value="{{ $currency }}" @selected(strtoupper($filters['currency']??'')===$currency)>{{ $currency }}</option>@endforeach</select></div>
            <div><label for="settlement-from">From</label><input id="settlement-from" type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}"></div>
            <div><label for="settlement-to">To</label><input id="settlement-to" type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}"></div>
            <button type="submit">Apply filters</button><a class="btn secondary" href="{{ route('admin.settlements') }}">Reset</a>
        </form>
        <div class="table-wrap"><table class="admin-table"><thead><tr><th>Event</th><th>Amount</th><th>Recipient</th><th>References</th><th>Status</th><th>Requested</th><th>Control</th></tr></thead><tbody>
        @forelse($settlements as $settlement)
            @php
                $number=(string)$settlement->payment_number;
                $masked=strlen($number)>4?str_repeat('•',max(0,strlen($number)-4)).substr($number,-4):$number;
            @endphp
            <tr><td><strong>{{ $settlement->event->title }}</strong><small>{{ $settlement->event->user->name }}</small></td><td><strong>{{ $settlement->currency }} {{ number_format((float)$settlement->amount,2) }}</strong></td><td>{{ $settlement->account_name ?: 'Account name unavailable' }}<small>{{ $settlement->payment_type }} · {{ $masked }}</small></td><td><strong>{{ $settlement->reference }}</strong><small>{{ $settlement->provider_reference ? 'Provider: '.$settlement->provider_reference : 'No provider reference' }}</small></td><td><span class="admin-role {{ $settlement->status==='paid'?'status-accepted':(in_array($settlement->status,['failed','reversed'],true)?'status-declined':'role-organiser') }}">{{ $settlement->status }}</span>@if($settlement->remarks)<small>{{ $settlement->remarks }}</small>@endif</td><td>{{ $settlement->created_at->format('d M Y') }}<small>{{ $settlement->requester?->name ?: 'System' }}</small></td><td><a class="table-control-link" href="{{ route('events.payments',$settlement->event) }}">Open event ledger →</a></td></tr>
        @empty<tr><td colspan="7">No settlement requests match these filters.</td></tr>@endforelse
        </tbody></table></div>
        {{ $settlements->links('partials.pagination') }}
    </section>
@endsection
