@extends('admin.layout')
@section('title', 'Organiser verification queue · System Admin')
@section('admin-content')
<div class="admin-verification-console">
    <header class="admin-page-head">
        <div>
            <span class="eyebrow">Trust and safety</span>
            <h1>Organiser verification</h1>
            <p>Review the oldest applications first, keep payout approval separate, and preserve a decision trail.</p>
        </div>
        <span>{{ $statusCounts['pending'] }} awaiting review</span>
    </header>

    @include('partials.alerts')
    <nav class="admin-status-tabs" aria-label="Verification status">
        @foreach([
            'pending' => 'Pending',
            'changes_requested' => 'Changes requested',
            'approved' => 'Approved',
            'rejected' => 'Rejected',
            'suspended' => 'Suspended',
            'expired' => 'Expired',
            'all' => 'All',
        ] as $value => $label)
            <a href="{{ route('admin.verifications.index', array_filter(['status' => $value, 'q' => $filters['q'] ?? null, 'level' => $filters['level'] ?? null, 'type' => $filters['type'] ?? null])) }}" @class(['is-active' => $filters['status'] === $value]) aria-current="{{ $filters['status'] === $value ? 'page' : 'false' }}">
                <span>{{ $label }}</span><strong>{{ $statusCounts[$value] ?? 0 }}</strong>
            </a>
        @endforeach
    </nav>

    <section class="admin-panel">
        <form class="admin-filters" method="get" action="{{ route('admin.verifications.index') }}">
            <input type="hidden" name="status" value="{{ $filters['status'] }}">
            <div><label for="verification-search">Search applications</label><input id="verification-search" type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Name, email, mobile or public name"></div>
            <div><label for="verification-level">Requested level</label><select id="verification-level" name="level"><option value="">All levels</option><option value="identity" @selected(($filters['level'] ?? '') === 'identity')>Identity</option><option value="business" @selected(($filters['level'] ?? '') === 'business')>Organisation</option></select></div>
            <div><label for="verification-type">Organiser type</label><select id="verification-type" name="type"><option value="">All types</option><option value="individual" @selected(($filters['type'] ?? '') === 'individual')>Individual</option><option value="professional" @selected(($filters['type'] ?? '') === 'professional')>Professional planner</option><option value="organisation" @selected(($filters['type'] ?? '') === 'organisation')>Organisation</option></select></div>
            <button type="submit">Apply filters</button>
            <a class="btn secondary" href="{{ route('admin.verifications.index', ['status' => $filters['status']]) }}">Reset</a>
        </form>

        <div class="admin-panel-heading">
            <div><span class="eyebrow">{{ str_replace('_', ' ', $filters['status']) }}</span><h2>{{ $verifications->total() }} application{{ $verifications->total() === 1 ? '' : 's' }}</h2></div>
            @if($filters['status'] === 'pending')<small>Ordered by oldest submission</small>@endif
        </div>

        <div class="table-wrap"><table class="admin-table admin-verification-table">
            <thead><tr><th>Organiser</th><th>Request</th><th>Status</th><th>Submitted / reviewed</th><th>Exposure</th><th>Review</th></tr></thead>
            <tbody>
            @forelse($verifications as $verification)
                @php($effectiveStatus = $verification->effectiveStatus())
                <tr>
                    <td><strong>{{ $verification->public_name ?: $verification->user->name }}</strong><small>{{ $verification->user->email ?: $verification->user->phone }}</small><small>Joined {{ $verification->user->created_at->format('d M Y') }}</small></td>
                    <td><strong>{{ ucfirst($verification->verification_level) }}</strong><small>{{ ucfirst($verification->organiser_type) }}</small></td>
                    <td><span class="verification-state state-{{ $effectiveStatus }}">{{ ucfirst(str_replace('_', ' ', $effectiveStatus)) }}</span>@if($verification->payout_name_verified_at)<small>✓ Payout matched</small>@endif</td>
                    <td>
                        @if($verification->submitted_at)<strong>{{ $verification->submitted_at->format('d M Y, H:i') }}</strong><small>{{ $verification->submitted_at->diffForHumans() }}</small>
                        @elseif($verification->reviewed_at)<strong>{{ $verification->reviewed_at->format('d M Y, H:i') }}</strong><small>Reviewed{{ $verification->reviewer ? ' by '.$verification->reviewer->name : '' }}</small>
                        @else<span>Not recorded</span>@endif
                    </td>
                    <td><strong>{{ $verification->events_count }} event{{ (int) $verification->events_count === 1 ? '' : 's' }}</strong></td>
                    <td><a class="table-control-link" href="{{ route('admin.verifications.show', $verification->user) }}">Open review →</a></td>
                </tr>
            @empty
                <tr><td colspan="6">No verification applications match these filters.</td></tr>
            @endforelse
            </tbody>
        </table></div>
        {{ $verifications->links('partials.pagination') }}
    </section>
</div>
@endsection
