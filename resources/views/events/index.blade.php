@extends('layout')
@section('title', 'Your events · Okesheni')
@section('content')
<main class="portfolio-dashboard">
    <header class="portfolio-hero">
        <div>
            <span class="eyebrow">Organiser workspace</span>
            <h1>Welcome back,<br><em>{{ auth()->user()->name }}.</em></h1>
            <p>Plan invitations, coordinate participants, and follow every response from one calm workspace.</p>
        </div>
        <div class="portfolio-hero-action"><span>Ready for the next occasion?</span><a class="btn" href="/events/create">＋ Create a new event</a></div>
    </header>

    @include('partials.alerts')

    <section class="portfolio-metrics" aria-label="Portfolio summary">
        <article><span class="metric-mark">01</span><div><strong>{{ $dashboardStats['events'] }}</strong><small>Total events</small></div></article>
        <article><span class="metric-mark">02</span><div><strong>{{ $dashboardStats['upcoming'] }}</strong><small>Upcoming events</small></div></article>
        <article><span class="metric-mark">03</span><div><strong>{{ $dashboardStats['invitees'] }}</strong><small>Participants invited</small></div></article>
        <article><span class="metric-mark">04</span><div><strong>{{ $dashboardStats['confirmed'] }}</strong><small>Confirmed responses</small></div></article>
    </section>

    <section class="portfolio-events">
        <div class="portfolio-section-head"><div><span class="eyebrow">Event portfolio</span><h2>Your occasions</h2><p>{{ $events->count() ? 'Select an event to manage invitations, participants, and responses.' : 'Your first event will appear here.' }}</p></div>@if($events->count())<a href="/events/create">New event <span aria-hidden="true">→</span></a>@endif</div>

        <div class="event-card-grid">
            @forelse($events as $event)
                @php
                    $complete = $event->responsesClosed();
                    $today = ! $complete && $event->starts_at->isToday();
                    $progress = $event->invitees_count ? round(($event->accepted_count / $event->invitees_count) * 100) : 0;
                @endphp
                <article class="portfolio-event-card {{ $complete ? 'is-complete' : '' }}">
                    <a class="event-card-main" href="{{ route('events.show', $event) }}" aria-label="Manage {{ $event->title }}">
                        <div class="event-card-top"><div class="event-date-block"><span>{{ $event->starts_at->format('M') }}</span><strong>{{ $event->starts_at->format('d') }}</strong><small>{{ $event->starts_at->format('Y') }}</small></div><span class="event-state {{ $complete ? 'complete' : ($today ? 'today' : 'upcoming') }}">{{ $complete ? 'Completed' : ($today ? 'Today' : 'Upcoming') }}</span></div>
                        <div class="event-card-copy"><span class="event-card-host">{{ $event->host }}</span><h3>{{ $event->title }}</h3><p><span aria-hidden="true">⌖</span> {{ $event->venue }}</p><p><span aria-hidden="true">◷</span> {{ $event->starts_at->format('g:i A') }} EAT</p></div>
                        <div class="event-response-progress"><div><span>Confirmed responses</span><strong>{{ $event->accepted_count }} of {{ $event->invitees_count }}</strong></div><div class="progress-track" aria-label="{{ $progress }} percent confirmed"><i style="width:{{ $progress }}%"></i></div></div>
                    </a>
                    <footer class="event-card-footer"><span>{{ $event->pending_count }} awaiting response</span><a href="{{ route('events.show', $event) }}">Manage event <span aria-hidden="true">→</span></a></footer>
                </article>
            @empty
                <article class="portfolio-empty">
                    <div class="empty-orbit" aria-hidden="true"><span>✦</span></div>
                    <span class="eyebrow">A blank canvas</span><h2>Your first occasion awaits.</h2><p>Create an event, shape the invitation, add your guest list, and send when everything feels right.</p><a class="btn" href="/events/create">Create your first event →</a>
                </article>
            @endforelse
        </div>
    </section>
</main>
@endsection
