@extends('layout')
@section('content')
<div class="page">
    <div class="page-head">
        <div><span class="eyebrow">{{ $event->host }}</span><h1>{{ $event->title }}</h1><p>{{ $event->starts_at->format('d M Y · g:i A') }} EAT · {{ $event->venue }}</p></div>
        <div class="page-actions"><a class="btn secondary" href="{{ route('events.design', $event) }}">Customize invitation</a><a class="btn secondary" href="{{ route('events.export', $event) }}">Export responses ↓</a></div>
    </div>
    @include('partials.alerts')
    <div class="stats">@foreach(['total'=>'Invited','accepted'=>'Confirmed','declined'=>'Declined','seats'=>'Seats confirmed'] as $key=>$label)<div class="panel"><span class="muted">{{ $label }}</span><strong>{{ $stats[$key] }}</strong></div>@endforeach</div>
    <section class="panel share-registration"><div><span class="eyebrow">Open event registration</span><h3>Share one link with any guest</h3><p>Registered guests can update their attendance, while new guests can add their details and confirm their place.</p></div><div class="share-link"><label for="registration-link">Public registration link</label><div><input id="registration-link" value="{{ $event->registrationUrl() }}" readonly><button type="button" id="copy-registration-link">Copy link</button><button type="button" class="btn secondary" id="share-registration-link">Share</button></div><small id="copy-status" aria-live="polite"></small></div></section>
    <div class="two">
        <section class="panel"><h3>Import your guests</h3><p class="muted">Download the sample, add your guests without changing its column names, then upload Excel or CSV. Up to 5,000 guests · max 5 MB.</p><a class="btn secondary small" href="{{ route('guest-template') }}">Download sample file ↓</a><form method="post" action="{{ route('events.import', $event) }}" enctype="multipart/form-data">@csrf<label for="guest-file">Completed guest list</label><input id="guest-file" type="file" name="file" accept=".xlsx,.xls,.csv" required><button style="margin-top:15px">Import guest list</button></form></section>
        <section class="panel"><h3>Send invitations</h3><p class="muted">Select guests from the list below, choose channels, then send. Selecting a guest who already received an invitation will resend it.</p><form id="send-invitations-form" method="post" action="{{ route('events.send', $event) }}">@csrf @foreach(['email'=>'Email','whatsapp'=>'WhatsApp','sms'=>'SMS'] as $key=>$label)<label><input type="checkbox" name="channels[]" value="{{ $key }}">{{ $label }}</label>@endforeach<label><input type="checkbox" name="consent" value="1" required>I have permission to contact these guests.</label><button>Send / resend selected ↗</button></form></section>
    </div>
    <div class="section-toolbar"><div><h3>Guest list & responses</h3><p class="muted guest-result-count">@if($invitees->total()) Showing {{ $invitees->firstItem() }}–{{ $invitees->lastItem() }} of {{ $invitees->total() }} matching guests @else No matching guests @endif</p></div><a class="btn small" href="{{ route('events.guests.create', $event) }}"><span aria-hidden="true">＋</span> Add guest</a></div>
    <form class="guest-filters panel" method="get" action="{{ route('events.show', $event) }}">
        <div class="filter-search"><label for="guest-search">Search guests</label><input id="guest-search" type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Name, email or phone"></div>
        <div><label for="rsvp-filter">RSVP response</label><select id="rsvp-filter" name="rsvp"><option value="">All responses</option>@foreach(['pending'=>'Pending','accepted'=>'Confirmed','declined'=>'Declined'] as $value=>$label)<option value="{{ $value }}" @selected(($filters['rsvp'] ?? '') === $value)>{{ $label }}</option>@endforeach</select></div>
        <div><label for="delivery-filter">Invitation status</label><select id="delivery-filter" name="delivery"><option value="">All invitations</option>@foreach(['not_sent'=>'Not sent','queued'=>'Queued','previewed'=>'Previewed','submitted'=>'Submitted','failed'=>'Failed'] as $value=>$label)<option value="{{ $value }}" @selected(($filters['delivery'] ?? '') === $value)>{{ $label }}</option>@endforeach</select></div>
        <div><label for="per-page-filter">Rows per page</label><select id="per-page-filter" name="per_page">@foreach([10,25,50,100] as $size)<option value="{{ $size }}" @selected((int) ($filters['per_page'] ?? 25) === $size)>{{ $size }}</option>@endforeach</select></div>
        <div class="filter-actions"><button type="submit">Apply filters</button><a class="btn secondary" href="{{ route('events.show', $event) }}">Clear</a></div>
    </form>
    <div class="table-wrap guest-table-wrap"><table class="guest-table">
        <thead><tr><th><input id="select-all-guests" type="checkbox" aria-label="Select all guests on this page"></th><th>Guest</th><th>Contact</th><th>RSVP</th><th>Seats</th><th>Invitation</th><th>Sending status</th><th><span class="sr-only">Actions</span></th></tr></thead>
        <tbody>@forelse($invitees as $guest)
            <tr><td class="guest-select"><input class="guest-selector" type="checkbox" name="guest_ids[]" value="{{ $guest->id }}" form="send-invitations-form" aria-label="Select {{ $guest->name }}"></td><td class="guest-identity" data-label="Guest"><strong>{{ $guest->name }}</strong><br><span class="muted">{{ $guest->dietary }}</span></td><td data-label="Contact">{{ $guest->email }}<br>{{ $guest->phone }}</td><td data-label="RSVP"><span class="tag">{{ $guest->rsvp_status }}</span></td><td data-label="Seats">{{ $guest->attending_count }} / {{ $guest->max_guests }}</td><td data-label="Invitation"><a target="_blank" rel="noopener" href="{{ $guest->rsvpUrl() }}">Open RSVP ↗</a></td><td data-label="Sending status">@forelse($guest->deliveries as $d)<div>{{ $d->channel }}: {{ $d->status }}</div>@if($d->error)<small>{{ $d->error }}</small>@endif @empty<span class="muted">Not sent</span>@endforelse</td><td class="guest-actions"><div class="row-actions"><a class="icon-btn" href="{{ route('events.guests.edit', [$event, $guest]) }}" aria-label="Edit {{ $guest->name }}" title="Edit guest">✎</a><form method="post" action="{{ route('events.guests.destroy', [$event, $guest]) }}" onsubmit="return confirm('Delete this guest from the guest list?')">@csrf @method('delete')<button class="icon-btn danger" type="submit" aria-label="Delete {{ $guest->name }}" title="Delete guest">×</button></form></div></td></tr>
        @empty<tr><td colspan="8">No guests match these filters. Clear the filters or add a new guest.</td></tr>@endforelse</tbody>
    </table></div>
    {{ $invitees->links('partials.pagination') }}
</div>
<script>
    (function () {
        const input=document.getElementById('registration-link'),copy=document.getElementById('copy-registration-link'),share=document.getElementById('share-registration-link'),status=document.getElementById('copy-status');
        copy.addEventListener('click',async function(){try{await navigator.clipboard.writeText(input.value);}catch(error){input.select();document.execCommand('copy');}status.textContent='Registration link copied.';setTimeout(function(){status.textContent='';},3000);});
        if(!navigator.share){share.hidden=true;}else share.addEventListener('click',function(){navigator.share({title:@json($event->title),text:'Register and confirm your attendance for '+@json($event->title),url:input.value});});
    }());
</script>
@endsection
