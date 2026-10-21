@extends('layout')
@section('title', 'Delivery monitoring · Super Admin')
@section('content')
<main class="admin-console">
    <header class="admin-page-head"><div><span class="eyebrow">Communication oversight</span><h1>Invitation deliveries</h1><p>Monitor invitation channels, provider status and failures across every event.</p></div><span>{{ $deliveries->total() }} records</span></header>
    @include('partials.alerts')
    @include('admin.nav')
    <section class="admin-panel">
        <form class="admin-filters" method="get"><div><label for="delivery-search">Search</label><input id="delivery-search" type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Participant, email or event"></div><div><label for="delivery-channel">Channel</label><select id="delivery-channel" name="channel"><option value="">All channels</option>@foreach(['email'=>'Email','sms'=>'SMS','whatsapp'=>'WhatsApp'] as $value=>$label)<option value="{{ $value }}" @selected(($filters['channel']??'')===$value)>{{ $label }}</option>@endforeach</select></div><div><label for="delivery-status">Status</label><select id="delivery-status" name="status"><option value="">All statuses</option>@foreach(['queued','previewed','submitted','failed'] as $status)<option value="{{ $status }}" @selected(($filters['status']??'')===$status)>{{ ucfirst($status) }}</option>@endforeach</select></div><button type="submit">Apply filters</button><a class="btn secondary" href="{{ route('admin.deliveries') }}">Reset</a></form>
        <div class="table-wrap"><table class="admin-table"><thead><tr><th>Participant</th><th>Event</th><th>Channel</th><th>Status</th><th>Updated</th><th>Details</th></tr></thead><tbody>@forelse($deliveries as $delivery)<tr><td><strong>{{ $delivery->invitee->name }}</strong><small>{{ $delivery->invitee->email ?: $delivery->invitee->phone }}</small></td><td><strong>{{ $delivery->invitee->event->title }}</strong><small>{{ $delivery->invitee->event->user->name }}</small></td><td>{{ strtoupper($delivery->channel) }}</td><td><span class="delivery-state">{{ $delivery->status }}</span></td><td>{{ $delivery->updated_at->diffForHumans() }}</td><td>@if($delivery->error)<small class="admin-error-copy">{{ $delivery->error }}</small>@else<a class="table-control-link" href="{{ route('events.participants',$delivery->invitee->event) }}">View participant →</a>@endif</td></tr>@empty<tr><td colspan="6">No delivery records match these filters.</td></tr>@endforelse</tbody></table></div>
        {{ $deliveries->links('partials.pagination') }}
    </section>
</main>
@endsection
