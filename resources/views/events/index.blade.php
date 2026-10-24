@extends('layout')
@section('title', __('ui.dashboard.your_occasions').' · Okesheni')
@section('content')
<main class="portfolio-dashboard">
    <header class="portfolio-hero">
        <div>
            <span class="eyebrow">{{ __('ui.dashboard.workspace') }}</span>
            <h1>{{ __('ui.dashboard.welcome') }}<br><em>{{ auth()->user()->name }}.</em></h1>
            <p>{{ __('ui.dashboard.intro') }}</p>
        </div>
        <div class="portfolio-hero-action"><span>{{ __('ui.dashboard.ready') }}</span><a class="btn" href="/events/create">＋ {{ __('ui.dashboard.create') }}</a></div>
    </header>

    @include('partials.alerts')

    @php
        $verification = auth()->user()->organiserVerification;
    @endphp
    <section class="portfolio-verification {{ $verification?->isApproved() ? 'is-verified' : '' }}">
        <div><span class="verification-state state-{{ $verification?->effectiveStatus() ?? 'unverified' }}">{{ $verification?->badgeLabel() ?: 'Standard organiser' }}</span><strong>{{ $verification?->isApproved() ? 'Your organiser badge is active.' : 'Verify to publish paid events.' }}</strong><p>{{ $verification?->isApproved() ? 'Guests can distinguish your verified profile from the event category.' : (($verification?->effectiveStatus() ?? null) === 'pending' ? 'Your application is under review. Invitation-only paid events remain available with manual collection.' : 'Invitation-only paid events remain available with manual collection. Public paid events and Bluetick Pay require verification.') }}</p></div>
        <a href="{{ route('organiser.verification.show') }}">{{ $verification?->isApproved() ? 'View verification' : 'Verify organiser' }} →</a>
    </section>

    <section class="portfolio-metrics" aria-label="Portfolio summary">
        <article><span class="metric-mark">01</span><div><strong>{{ $dashboardStats['events'] }}</strong><small>{{ __('ui.dashboard.total') }}</small></div></article>
        <article><span class="metric-mark">02</span><div><strong>{{ $dashboardStats['upcoming'] }}</strong><small>{{ __('ui.dashboard.upcoming') }}</small></div></article>
        <article><span class="metric-mark">03</span><div><strong>{{ $dashboardStats['invitees'] }}</strong><small>{{ __('ui.dashboard.invited') }}</small></div></article>
        <article><span class="metric-mark">04</span><div><strong>{{ $dashboardStats['confirmed'] }}</strong><small>{{ __('ui.dashboard.confirmed') }}</small></div></article>
    </section>

    <section class="portfolio-events">
        <div class="portfolio-section-head"><div><span class="eyebrow">{{ __('ui.dashboard.portfolio') }}</span><h2>{{ __('ui.dashboard.your_occasions') }}</h2><p>{{ $events->count() ? __('ui.dashboard.select_event') : __('ui.dashboard.first_appears') }}</p></div>@if($events->count())<a href="/events/create">{{ __('ui.dashboard.new_event') }} <span aria-hidden="true">→</span></a>@endif</div>

        <div class="event-card-grid">
            @forelse($events as $event)
                @php
                    $complete = $event->responsesClosed();
                    $today = ! $complete && $event->starts_at->isToday();
                    $progress = $event->invitees_count ? round(($event->accepted_count / $event->invitees_count) * 100) : 0;
                @endphp
                <article class="portfolio-event-card {{ $complete ? 'is-complete' : '' }}">
                    <a class="event-card-main" href="{{ route('events.show', $event) }}" aria-label="Manage {{ $event->title }}">
                        <div class="event-card-top"><div class="event-date-block"><span>{{ $event->starts_at->format('M') }}</span><strong>{{ $event->starts_at->format('d') }}</strong><small>{{ $event->starts_at->format('Y') }}</small></div><span class="event-state {{ $complete ? 'complete' : ($today ? 'today' : 'upcoming') }}">{{ $complete ? __('ui.dashboard.completed') : ($today ? __('ui.dashboard.today') : __('ui.dashboard.upcoming_state')) }}</span></div>
                        <div class="event-card-copy"><span class="event-card-host">{{ $event->host }}</span><h3>{{ $event->title }}</h3><p><span aria-hidden="true">⌖</span> {{ $event->venue }}</p><p><span aria-hidden="true">◷</span> {{ $event->starts_at->format('g:i A') }} EAT</p></div>
                        <div class="event-response-progress"><div><span>{{ __('ui.dashboard.confirmed') }}</span><strong>{{ __('ui.dashboard.count_of', ['confirmed' => $event->accepted_count, 'total' => $event->invitees_count]) }}</strong></div><div class="progress-track" aria-label="{{ $progress }} percent confirmed"><i style="width:{{ $progress }}%"></i></div></div>
                    </a>
                    <footer class="event-card-footer"><span>{{ __('ui.dashboard.awaiting', ['count' => $event->pending_count]) }}</span><a href="{{ route('events.show', $event) }}">{{ __('ui.dashboard.manage') }} <span aria-hidden="true">→</span></a></footer>
                </article>
            @empty
                <article class="portfolio-empty">
                    <div class="empty-orbit" aria-hidden="true"><span>✦</span></div>
                    <span class="eyebrow">{{ __('ui.dashboard.blank') }}</span><h2>{{ __('ui.dashboard.first_title') }}</h2><p>{{ __('ui.dashboard.first_copy') }}</p><a class="btn" href="/events/create">{{ __('ui.dashboard.first_button') }} →</a>
                </article>
            @endforelse
        </div>
    </section>
</main>
@endsection
