@extends('layout')
@section('content')
<div class="page">
    <div class="page-head">
        <div><span class="eyebrow">{{ $event->host }}</span><h1>{{ $event->title }}</h1><p>{{ $event->starts_at->format('d M Y · g:i A') }} EAT · {{ $event->venue }}</p></div>
        <div class="page-actions"><a class="btn secondary" href="{{ route('events.design', $event) }}">Customize invitation</a><a class="btn secondary" href="{{ route('events.export', $event) }}">Export responses ↓</a></div>
    </div>
    @include('partials.alerts')
    <div class="stats">@foreach(['total'=>'Invited','accepted'=>'Confirmed','declined'=>'Declined','seats'=>'Seats confirmed'] as $key=>$label)<div class="panel"><span class="muted">{{ $label }}</span><strong>{{ $stats[$key] }}</strong></div>@endforeach</div>
    <div class="two">
        <section class="panel"><h3>Import your guests</h3><p class="muted">Download the sample, add your guests without changing its column names, then upload Excel or CSV. Up to 5,000 guests · max 5 MB.</p><a class="btn secondary small" href="{{ route('guest-template') }}">Download sample file ↓</a><form method="post" action="{{ route('events.import', $event) }}" enctype="multipart/form-data">@csrf<label for="guest-file">Completed guest list</label><input id="guest-file" type="file" name="file" accept=".xlsx,.xls,.csv" required><button style="margin-top:15px">Import guest list</button></form></section>
        <section class="panel"><h3>Send invitations</h3><p class="muted">Select guests from the list below, choose channels, then send. Selecting a guest who already received an invitation will resend it.</p><form id="send-invitations-form" method="post" action="{{ route('events.send', $event) }}">@csrf @foreach(['email'=>'Email','whatsapp'=>'WhatsApp','sms'=>'SMS'] as $key=>$label)<label><input type="checkbox" name="channels[]" value="{{ $key }}">{{ $label }}</label>@endforeach<label><input type="checkbox" name="consent" value="1" required>I have permission to contact these guests.</label><button>Send / resend selected ↗</button></form></section>
    </div>
    <div class="section-toolbar"><h3>Guest list & responses</h3><a class="btn small" href="{{ route('events.guests.create', $event) }}"><span aria-hidden="true">＋</span> Add guest</a></div>
    <div class="table-wrap"><table>
        <thead><tr><th><input id="select-all-guests" type="checkbox" aria-label="Select all guests on this page"></th><th>Guest</th><th>Contact</th><th>RSVP</th><th>Seats</th><th>Invitation</th><th>Sending status</th><th><span class="sr-only">Actions</span></th></tr></thead>
        <tbody>@forelse($invitees as $guest)
            <tr><td><input class="guest-selector" type="checkbox" name="guest_ids[]" value="{{ $guest->id }}" form="send-invitations-form" aria-label="Select {{ $guest->name }}"></td><td>{{ $guest->name }}<br><span class="muted">{{ $guest->dietary }}</span></td><td>{{ $guest->email }}<br>{{ $guest->phone }}</td><td><span class="tag">{{ $guest->rsvp_status }}</span></td><td>{{ $guest->attending_count }} / {{ $guest->max_guests }}</td><td><a target="_blank" rel="noopener" href="{{ $guest->rsvpUrl() }}">Open RSVP ↗</a></td><td>@foreach($guest->deliveries as $d)<div>{{ $d->channel }}: {{ $d->status }}</div>@if($d->error)<small>{{ $d->error }}</small>@endif @endforeach</td><td><div class="row-actions"><a class="icon-btn" href="{{ route('events.guests.edit', [$event, $guest]) }}" aria-label="Edit {{ $guest->name }}" title="Edit guest">✎</a><form method="post" action="{{ route('events.guests.destroy', [$event, $guest]) }}" onsubmit="return confirm('Delete this guest from the guest list?')">@csrf @method('delete')<button class="icon-btn danger" type="submit" aria-label="Delete {{ $guest->name }}" title="Delete guest">×</button></form></div></td></tr>
        @empty<tr><td colspan="8">No guests yet. Add your first guest or import a guest list.</td></tr>@endforelse</tbody>
    </table></div>
    <div style="margin-top:20px">{{ $invitees->links() }}</div>
</div>
@endsection
