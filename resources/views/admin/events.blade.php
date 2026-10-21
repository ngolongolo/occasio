@extends('layout')
@section('title', 'Event control · Super Admin')
@section('content')
<main class="admin-console">
    <header class="admin-page-head"><div><span class="eyebrow">Platform events</span><h1>Event control</h1><p>Find any event, identify its organiser and open the complete workspace for intervention.</p></div><span>{{ $events->total() }} events</span></header>
    @include('partials.alerts')
    @include('admin.nav')
    <section class="admin-panel">
        <form class="admin-filters" method="get"><div><label for="admin-event-search">Search events</label><input id="admin-event-search" type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Event, host, venue or organiser"></div><div><label for="admin-event-state">Status</label><select id="admin-event-state" name="state"><option value="">All statuses</option><option value="upcoming" @selected(($filters['state']??'')==='upcoming')>Upcoming</option><option value="completed" @selected(($filters['state']??'')==='completed')>Completed</option></select></div><button type="submit">Apply filters</button><a class="btn secondary" href="{{ route('admin.events') }}">Reset</a></form>
        <div class="table-wrap"><table class="admin-table"><thead><tr><th>Event</th><th>Organiser</th><th>Schedule</th><th>Participants</th><th>Status</th><th>Control</th></tr></thead><tbody>@forelse($events as $event)<tr><td><strong>{{ $event->title }}</strong><small>{{ $event->host }} · {{ $event->venue }}</small></td><td><strong>{{ $event->user->name }}</strong><small>{{ $event->user->email }}</small></td><td>{{ $event->starts_at->format('d M Y') }}<small>{{ $event->starts_at->format('g:i A') }} EAT</small></td><td>{{ $event->invitees_count }}</td><td><span class="admin-role {{ $event->responsesClosed()?'role-organiser':'status-accepted' }}">{{ $event->responsesClosed()?'Completed':'Upcoming' }}</span></td><td><a class="table-control-link" href="{{ route('events.show',$event) }}">Open workspace →</a></td></tr>@empty<tr><td colspan="6">No events match these filters.</td></tr>@endforelse</tbody></table></div>
        {{ $events->links('partials.pagination') }}
    </section>
</main>
@endsection
