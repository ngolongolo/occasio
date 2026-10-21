@extends('layout')
@section('title', 'Super Admin · Okesheni')
@section('content')
<main class="admin-console">
    <header class="admin-hero">
        <div><span class="eyebrow">Platform oversight</span><h1>Super Admin</h1><p>Monitor organisers, events, participant activity, invitation delivery and recorded payments across Okesheni.</p></div>
        <div class="admin-identity"><small>Signed in with full oversight</small><strong>{{ auth()->user()->name }}</strong><span>{{ auth()->user()->email }}</span></div>
    </header>

    @include('partials.alerts')
    @include('admin.nav')

    <section class="admin-metrics" aria-label="Platform totals">
        <article><span>Organisers</span><strong>{{ $stats['organisers'] }}</strong><small>{{ $stats['super_admins'] }} Super Admin{{ $stats['super_admins']===1?'':'s' }}</small></article>
        <article><span>Events</span><strong>{{ $stats['events'] }}</strong><small>Across all organisers</small></article>
        <article><span>Participants</span><strong>{{ $stats['participants'] }}</strong><small>All event records</small></article>
        <article><span>Invitations</span><strong>{{ $stats['deliveries'] }}</strong><small>Delivery records</small></article>
        <article><span>Payments</span><strong>{{ $stats['payments'] }}</strong><small>Recorded installments</small></article>
    </section>

    <div class="admin-overview-grid">
        <section class="admin-panel">
            <div class="admin-panel-heading"><div><span class="eyebrow">Recent activity</span><h2>Latest events</h2></div></div>
            <div class="admin-activity-list">@forelse($recentEvents as $event)<a href="{{ route('events.show',$event) }}"><span class="admin-activity-icon">◇</span><div><strong>{{ $event->title }}</strong><small>{{ $event->user->name }} · {{ $event->invitees_count }} participants</small></div><time>{{ $event->created_at->diffForHumans() }}</time></a>@empty<p class="muted">No events created yet.</p>@endforelse</div>
        </section>
        <section class="admin-panel">
            <div class="admin-panel-heading"><div><span class="eyebrow">Delivery activity</span><h2>Latest invitations</h2></div></div>
            <div class="admin-activity-list">@forelse($recentDeliveries as $delivery)<a href="{{ route('events.participants',$delivery->invitee->event) }}"><span class="admin-activity-icon">↗</span><div><strong>{{ $delivery->invitee->name }}</strong><small>{{ $delivery->channel }} · {{ $delivery->invitee->event->title }}</small></div><span class="delivery-state">{{ $delivery->status }}</span></a>@empty<p class="muted">No invitation delivery activity yet.</p>@endforelse</div>
        </section>
    </div>
</main>
@endsection
