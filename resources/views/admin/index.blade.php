@extends('admin.layout')
@section('title', 'System Admin · Okesheni')
@section('admin-content')
    <header class="admin-hero">
        <div><span class="eyebrow">Platform oversight</span><h1>System overview</h1><p>Prioritise trust reviews, monitor event operations and reconcile participation collections without mixing currencies.</p></div>
        <div class="admin-identity"><small>Signed in with system oversight</small><strong>{{ auth()->user()->name }}</strong><span>{{ now()->format('d M Y · H:i') }} EAT</span></div>
    </header>

    @include('partials.alerts')
    <section class="admin-metrics" aria-label="Operational priorities">
        <article><span>Pending verification</span><strong>{{ number_format($kpis['pending_verifications']) }}</strong><small>{{ $kpis['oldest_verification_days'] === null ? 'No applications waiting' : 'Oldest waiting '.$kpis['oldest_verification_days'].' day'.($kpis['oldest_verification_days']===1?'':'s') }}</small><a class="table-control-link" href="{{ route('admin.verifications.index',['status'=>'pending']) }}">Open review queue →</a></article>
        <article><span>Upcoming events</span><strong>{{ number_format($kpis['upcoming_events']) }}</strong><small>{{ number_format($kpis['upcoming_30_days']) }} in the next 30 days</small><a class="table-control-link" href="{{ route('admin.events',['state'=>'upcoming']) }}">View events →</a></article>
        <article><span>Confirmed seats</span><strong>{{ number_format($kpis['recent_confirmed_seats']) }}</strong><small>Confirmed in the last 30 days</small></article>
        <article><span>Delivery failures</span><strong>{{ number_format($kpis['delivery_failures']) }}</strong><small>Invitation and notification failures</small><a class="table-control-link" href="{{ route('admin.deliveries',['status'=>'failed']) }}">Investigate →</a></article>
        <article><span>Settlement backlog</span><strong>{{ number_format($kpis['pending_settlements']) }}</strong><small>Initiated or pending payout requests</small><a class="table-control-link" href="{{ route('admin.settlements',['status'=>'pending']) }}">Review settlements →</a></article>
    </section>

    <section class="admin-panel" aria-labelledby="collection-position-heading">
        <div class="admin-panel-heading"><div><span class="eyebrow">Money movement</span><h2 id="collection-position-heading">Participation collection position</h2><p>Confirmed collections minus completed and in-flight settlements. Every currency is reported independently.</p></div><a class="table-control-link" href="{{ route('admin.payments') }}">Open transactions →</a></div>
        @if($collections->isEmpty())
            <p class="muted">No confirmed participation collections yet.</p>
        @else
            <div class="admin-metrics">
                @foreach($collections as $position)
                    <article><span>{{ $position['currency'] }} available to settle</span><strong>{{ $position['currency'] }} {{ number_format($position['available'],2) }}</strong><small>{{ $position['currency'] }} {{ number_format($position['collected'],2) }} collected · {{ $position['currency'] }} {{ number_format($position['reserved'],2) }} settled/reserved · {{ $position['pending_settlement_count'] }} pending</small></article>
                @endforeach
            </div>
        @endif
    </section>

    @php
        $chartMaximum=max(1,collect($activity)->max(fn($row)=>max($row['events'],$row['confirmed_seats'])));
        $chartCount=max(1,count($activity)-1);
        $eventPoints=collect($activity)->map(fn($row,$index)=>(20+($index/$chartCount)*700).','.(200-($row['events']/$chartMaximum)*160))->implode(' ');
        $seatPoints=collect($activity)->map(fn($row,$index)=>(20+($index/$chartCount)*700).','.(200-($row['confirmed_seats']/$chartMaximum)*160))->implode(' ');
    @endphp
    <section class="admin-panel" aria-labelledby="activity-heading">
        <div class="admin-panel-heading"><div><span class="eyebrow">Last 30 days</span><h2 id="activity-heading">Platform activity</h2><p>New events and confirmed attendance seats by day.</p></div><div><span class="delivery-state">Events</span> <span class="admin-role status-accepted">Confirmed seats</span></div></div>
        <svg class="admin-report-chart" viewBox="0 0 740 230" role="img" aria-labelledby="activity-chart-title activity-chart-description" preserveAspectRatio="none">
            <title id="activity-chart-title">Thirty-day events and confirmed seats trend</title>
            <desc id="activity-chart-description">Two lines compare new events and newly confirmed attendance seats for each of the last thirty days. Exact daily values follow the chart.</desc>
            @foreach([40,80,120,160,200] as $y)<line x1="20" y1="{{ $y }}" x2="720" y2="{{ $y }}" stroke="currentColor" opacity=".1" vector-effect="non-scaling-stroke"/>@endforeach
            <polyline points="{{ $eventPoints }}" fill="none" stroke="#8f5a73" stroke-width="3" vector-effect="non-scaling-stroke"/>
            <polyline points="{{ $seatPoints }}" fill="none" stroke="#26846c" stroke-width="3" vector-effect="non-scaling-stroke"/>
        </svg>
        <details><summary>View exact daily activity</summary><div class="table-wrap"><table class="admin-table"><thead><tr><th>Date</th><th>New events</th><th>Confirmed seats</th></tr></thead><tbody>@foreach($activity as $day)<tr><td>{{ $day['label'] }}</td><td>{{ number_format($day['events']) }}</td><td>{{ number_format($day['confirmed_seats']) }}</td></tr>@endforeach</tbody></table></div></details>
    </section>

    <div class="admin-overview-grid">
        <section class="admin-panel" aria-labelledby="queue-heading">
            <div class="admin-panel-heading"><div><span class="eyebrow">Requires attention</span><h2 id="queue-heading">Action queue</h2></div></div>
            <div class="admin-activity-list">@forelse($actionQueue as $item)<a href="{{ $item['url'] }}"><span class="admin-activity-icon">{{ $item['priority']==='urgent'?'!':'→' }}</span><div><strong>{{ $item['title'] }}</strong><small>{{ $item['detail'] }}</small></div><time>{{ $item['age'] }}</time></a>@empty<p class="muted">No outstanding operational exceptions.</p>@endforelse</div>
        </section>
        <section class="admin-panel" aria-labelledby="upcoming-heading">
            <div class="admin-panel-heading"><div><span class="eyebrow">Event operations</span><h2 id="upcoming-heading">Next events</h2></div><a class="table-control-link" href="{{ route('admin.events',['state'=>'upcoming']) }}">View all →</a></div>
            <div class="admin-activity-list">@forelse($upcomingEvents as $event)<a href="{{ route('events.show',$event) }}"><span class="admin-activity-icon">◇</span><div><strong>{{ $event->title }}</strong><small>{{ $event->user->name }} · {{ number_format((int)$event->confirmed_seats) }} confirmed seat{{ (int)$event->confirmed_seats===1?'':'s' }}@if($event->capacity) / {{ number_format($event->capacity) }} capacity @endif</small></div><time>{{ $event->starts_at->format('d M') }}</time></a>@empty<p class="muted">No upcoming events.</p>@endforelse</div>
        </section>
    </div>

    <div class="admin-overview-grid">
        <section class="admin-panel" aria-labelledby="funnel-heading">
            <div class="admin-panel-heading"><div><span class="eyebrow">Participant journey</span><h2 id="funnel-heading">RSVP funnel</h2></div></div>
            @php($funnelMaximum=max(1,$rsvpFunnel[0]['value']??0))
            <div class="admin-report-bars">@foreach($rsvpFunnel as $stage)<div><span>{{ $stage['label'] }}</span><progress max="{{ $funnelMaximum }}" value="{{ $stage['value'] }}">{{ $stage['value'] }}</progress><strong>{{ number_format($stage['value']) }}</strong></div>@endforeach</div>
        </section>
        <section class="admin-panel" aria-labelledby="verification-heading">
            <div class="admin-panel-heading"><div><span class="eyebrow">Trust and safety</span><h2 id="verification-heading">Organiser verification</h2></div></div>
            @php($verificationMaximum=max(1,$stats['organisers']))
            <div class="admin-report-bars">@foreach($verificationDistribution as $row)<div><span>{{ ucfirst(str_replace('_',' ',$row['status'])) }}</span><progress max="{{ $verificationMaximum }}" value="{{ $row['count'] }}">{{ $row['count'] }}</progress><strong>{{ number_format($row['count']) }}</strong></div>@endforeach</div>
        </section>
    </div>

    <section class="admin-panel" aria-labelledby="delivery-heading">
        <div class="admin-panel-heading"><div><span class="eyebrow">Communication health</span><h2 id="delivery-heading">Delivery channel and status</h2><p>Invitation and configured notification deliveries combined.</p></div><a class="table-control-link" href="{{ route('admin.deliveries') }}">Open delivery log →</a></div>
        <div class="table-wrap"><table class="admin-table"><thead><tr><th>Channel</th><th>Status</th><th>Deliveries</th></tr></thead><tbody>@forelse($deliveryBreakdown as $row)<tr><td>{{ strtoupper($row['channel']) }}</td><td><span class="delivery-state">{{ str_replace('_',' ',$row['status']) }}</span></td><td>{{ number_format($row['count']) }}</td></tr>@empty<tr><td colspan="3">No delivery activity yet.</td></tr>@endforelse</tbody></table></div>
    </section>

    <section class="admin-panel" aria-label="Platform footprint"><div class="admin-panel-heading"><div><span class="eyebrow">Platform footprint</span><h2>All-time records</h2></div></div><div class="admin-metrics"><article><span>Organisers</span><strong>{{ number_format($stats['organisers']) }}</strong><small>{{ number_format($stats['super_admins']) }} system administrator{{ $stats['super_admins']===1?'':'s' }}</small></article><article><span>Events</span><strong>{{ number_format($stats['events']) }}</strong><small>Across all organisers</small></article><article><span>Participant records</span><strong>{{ number_format($stats['participants']) }}</strong><small>Across every event</small></article></div></section>
@endsection
