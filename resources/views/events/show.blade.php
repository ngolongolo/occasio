@extends('layout')
@section('title', $event->title.' · Event workspace')
@section('content')
<main class="event-workspace">
    <header class="event-hero">
        <div class="event-hero-copy">
            <a class="event-back" href="/dashboard">← All events</a>
            <span class="event-host">{{ $event->host }}</span>
            <h1>{{ $event->title }}</h1>
            <div class="event-facts">
                <span><b>Calendar</b>{{ $event->starts_at->format('D, d M Y · g:i A') }} EAT</span>
                <span><b>Location</b>{{ $event->venue }}</span>
                <span><b>Responses close</b>{{ $event->rsvp_deadline->format('d M Y') }}</span>
            </div>
        </div>
        <div class="event-hero-actions">
            <a class="btn event-primary-action" href="{{ route('events.design', $event) }}">Customize invitation</a>
            <a class="btn event-quiet-action" href="{{ route('events.export', $event) }}">Export responses ↓</a>
        </div>
    </header>

    @include('partials.alerts')

    <section class="event-metrics" aria-label="Event summary">
        <article><span>Invited</span><strong>{{ $stats['total'] }}</strong><small>Total participant records</small></article>
        @if($event->participation_enabled)
            <article><span>Confirmed</span><strong>{{ $stats['accepted'] }}</strong><small>{{ $stats['seats'] }} seats attending</small></article>
            <article><span>Declined</span><strong>{{ $stats['declined'] }}</strong><small>{{ max(0, $stats['total']-$stats['accepted']-$stats['declined']) }} awaiting response</small></article>
        @endif
        @if($event->pledge_enabled)
            <article><span>Total pledged</span><strong class="metric-money">{{ $event->pledge_currency }} {{ number_format($stats['pledged'], 2) }}</strong><small>All recorded pledges</small></article>
            <article><span>Payment confirmed</span><strong class="metric-money">{{ $event->pledge_currency }} {{ number_format($stats['confirmed_pledges'], 2) }}</strong><small>Verified by an administrator</small></article>
        @endif
    </section>

    <section class="workspace-section">
        <div class="workspace-heading"><div><span class="eyebrow">Invitation operations</span><h2>Prepare and reach your guests</h2></div><p>Share, import, and send from one place.</p></div>
        <div class="event-operation-grid">
            @if($event->participation_enabled)
            <article class="operation-card operation-share">
                <div class="operation-icon" aria-hidden="true">↗</div>
                <div><span class="operation-step">01 · Share</span><h3>Open registration</h3><p>Anyone with this link can register or update their attendance.</p></div>
                <div class="share-link"><label for="registration-link">Public registration link</label><div><input id="registration-link" value="{{ $event->registrationUrl() }}" readonly><button type="button" id="copy-registration-link">Copy</button><button type="button" class="btn secondary" id="share-registration-link">Share</button></div><small id="copy-status" aria-live="polite"></small></div>
            </article>
            @endif
            <article class="operation-card">
                <div class="operation-icon" aria-hidden="true">⇧</div>
                <div><span class="operation-step">{{ $event->participation_enabled ? '02' : '01' }} · Import</span><h3>Upload a guest list</h3><p>Use the spreadsheet template for larger guest lists. Maximum 5,000 rows.</p></div>
                <a class="text-action" href="{{ route('guest-template') }}">Download spreadsheet template ↓</a>
                <form method="post" action="{{ route('events.import', $event) }}" enctype="multipart/form-data">@csrf<label for="guest-file">Completed Excel or CSV file</label><input id="guest-file" type="file" name="file" accept=".xlsx,.xls,.csv" required><button type="submit">Import guests</button></form>
            </article>
            <article class="operation-card">
                <div class="operation-icon" aria-hidden="true">✦</div>
                <div><span class="operation-step">{{ $event->participation_enabled ? '03' : '02' }} · Deliver</span><h3>Send invitations</h3><p>Select participants below, then choose how their personal invitation should be delivered.</p></div>
                <form id="send-invitations-form" method="post" action="{{ route('events.send', $event) }}">@csrf<div class="channel-options">@foreach(['email'=>'Email','whatsapp'=>'WhatsApp','sms'=>'SMS'] as $key=>$label)<label><input type="checkbox" name="channels[]" value="{{ $key }}"><span>{{ $label }}</span></label>@endforeach</div><label class="consent-row"><input type="checkbox" name="consent" value="1" required><span>I have permission to contact these guests.</span></label><button type="submit">Send selected invitations →</button></form>
            </article>
        </div>
    </section>

    <section class="workspace-section participant-section">
        <div class="workspace-heading"><div><span class="eyebrow">Participant directory</span><h2>Guests and responses</h2><p class="guest-result-count">@if($invitees->total()) Showing {{ $invitees->firstItem() }}–{{ $invitees->lastItem() }} of {{ $invitees->total() }} matching participants @else No matching participants @endif</p></div><button class="btn secondary" type="button" id="toggle-participant-form" aria-expanded="{{ $errors->any() ? 'true' : 'false' }}" aria-controls="quick-participant-form">＋ Add one participant</button></div>

        <div id="quick-participant-form" class="quick-participant-form" @if(!$errors->any()) hidden @endif>
            <div class="quick-form-intro"><span class="eyebrow">New participant</span><h3>Add someone to the guest list</h3><p>Provide an email, phone number, or both. Phone numbers must include the country code.</p></div>
            <form method="post" action="{{ route('events.guests.store', $event) }}">@csrf<div><label for="participant-name">Full name</label><input id="participant-name" name="name" value="{{ old('name') }}" maxlength="150" autocomplete="name" required></div><div><label for="participant-email">Email address</label><input id="participant-email" type="email" name="email" value="{{ old('email') }}" maxlength="180" autocomplete="email"></div><div><label for="participant-phone">Phone number</label><input id="participant-phone" type="tel" name="phone" value="{{ old('phone') }}" placeholder="+255784311111" autocomplete="tel"></div><div><label for="participant-max-guests">Maximum guests</label><input id="participant-max-guests" type="number" name="max_guests" value="{{ old('max_guests', 1) }}" min="1" max="10" required></div><button type="submit">Add participant</button></form>
        </div>

        <form class="guest-filters" method="get" action="{{ route('events.show', $event) }}">
            <div class="filter-search"><label for="guest-search">Search participants</label><input id="guest-search" type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Name, email or phone"></div>
            @if($event->participation_enabled)<div><label for="rsvp-filter">Response</label><select id="rsvp-filter" name="rsvp"><option value="">All responses</option>@foreach(['pending'=>'Pending','accepted'=>'Confirmed','declined'=>'Declined'] as $value=>$label)<option value="{{ $value }}" @selected(($filters['rsvp'] ?? '') === $value)>{{ $label }}</option>@endforeach</select></div>@endif
            <div><label for="delivery-filter">Invitation</label><select id="delivery-filter" name="delivery"><option value="">All invitations</option>@foreach(['not_sent'=>'Not sent','queued'=>'Queued','previewed'=>'Previewed','submitted'=>'Submitted','failed'=>'Failed'] as $value=>$label)<option value="{{ $value }}" @selected(($filters['delivery'] ?? '') === $value)>{{ $label }}</option>@endforeach</select></div>
            <div><label for="per-page-filter">Rows</label><select id="per-page-filter" name="per_page">@foreach([10,25,50,100] as $size)<option value="{{ $size }}" @selected((int) ($filters['per_page'] ?? 25) === $size)>{{ $size }}</option>@endforeach</select></div>
            <div class="filter-actions"><button type="submit">Apply</button><a class="btn secondary" href="{{ route('events.show', $event) }}">Reset</a></div>
        </form>

        <div class="table-wrap guest-table-wrap guest-table-shell"><table class="guest-table">
            <thead><tr><th><input id="select-all-guests" type="checkbox" aria-label="Select all participants on this page"></th><th>Participant</th><th>Contact</th>@if($event->participation_enabled)<th>Response</th><th>Seats</th>@endif @if($event->pledge_enabled)<th>Pledge</th>@endif<th>Invitation</th><th>Delivery</th><th><span class="sr-only">Actions</span></th></tr></thead>
            <tbody>@forelse($invitees as $guest)
                <tr><td class="guest-select"><input class="guest-selector" type="checkbox" name="guest_ids[]" value="{{ $guest->id }}" form="send-invitations-form" aria-label="Select {{ $guest->name }}"></td><td class="guest-identity" data-label="Participant"><span class="guest-avatar" aria-hidden="true">{{ strtoupper(mb_substr($guest->name,0,1)) }}</span><div><strong>{{ $guest->name }}</strong>@if($guest->dietary)<small>{{ $guest->dietary }}</small>@endif</div></td><td data-label="Contact"><span class="contact-line">{{ $guest->email ?: '—' }}</span><span class="contact-line">{{ $guest->phone ?: '—' }}</span></td>@if($event->participation_enabled)<td data-label="Response"><span class="status-pill status-{{ $guest->rsvp_status }}">{{ $guest->rsvp_status }}</span></td><td data-label="Seats"><strong>{{ $guest->attending_count }}</strong> <span class="muted">/ {{ $guest->max_guests }}</span></td>@endif @if($event->pledge_enabled)<td data-label="Pledge">@if($guest->pledge)<strong>{{ $event->pledge_currency }} {{ number_format($guest->pledge->amount,2) }}</strong><form method="post" action="{{ route('events.pledges.update',[$event,$guest->pledge]) }}">@csrf @method('put')<select name="status" aria-label="Pledge status for {{ $guest->name }}" onchange="this.form.submit()">@foreach(['pledged'=>'Pledged','payment_reported'=>'Payment reported','confirmed'=>'Confirmed','cancelled'=>'Cancelled'] as $value=>$label)<option value="{{ $value }}" @selected($guest->pledge->status===$value)>{{ $label }}</option>@endforeach</select></form>@if($guest->pledge->payment_reference)<small>Ref: {{ $guest->pledge->payment_reference }}</small>@endif @else<span class="muted">No pledge</span>@endif</td>@endif<td data-label="Invitation"><a class="table-link" target="_blank" rel="noopener" href="{{ $guest->rsvpUrl() }}">Open ↗</a></td><td data-label="Delivery"><div class="delivery-list">@forelse($guest->deliveries as $d)<span class="delivery-state"><b>{{ $d->channel }}</b> {{ $d->status }}</span>@if($d->error)<small>{{ $d->error }}</small>@endif @empty<span class="muted">Not sent</span>@endforelse</div></td><td class="guest-actions"><div class="row-actions"><a class="icon-btn" href="{{ route('events.guests.edit', [$event, $guest]) }}" aria-label="Edit {{ $guest->name }}" title="Edit participant">✎</a><form method="post" action="{{ route('events.guests.destroy', [$event, $guest]) }}" onsubmit="return confirm('Delete this participant from the guest list?')">@csrf @method('delete')<button class="icon-btn danger" type="submit" aria-label="Delete {{ $guest->name }}" title="Delete participant">×</button></form></div></td></tr>
            @empty<tr><td colspan="{{ 6 + ($event->participation_enabled ? 2 : 0) + ($event->pledge_enabled ? 1 : 0) }}" class="empty-participants"><strong>No participants found</strong><span>Add someone above or clear the current filters.</span></td></tr>@endforelse</tbody>
        </table></div>
        {{ $invitees->links('partials.pagination') }}
    </section>
</main>
<script>
    (function () {
        const form=document.getElementById('quick-participant-form'),toggle=document.getElementById('toggle-participant-form');
        toggle.addEventListener('click',function(){const opening=form.hidden;form.hidden=!opening;toggle.setAttribute('aria-expanded',opening?'true':'false');if(opening)document.getElementById('participant-name').focus();});
        const input=document.getElementById('registration-link'),copy=document.getElementById('copy-registration-link'),share=document.getElementById('share-registration-link'),status=document.getElementById('copy-status');
        if(!input)return;
        copy.addEventListener('click',async function(){try{await navigator.clipboard.writeText(input.value);}catch(error){input.select();document.execCommand('copy');}status.textContent='Link copied to clipboard.';setTimeout(function(){status.textContent='';},3000);});
        if(!navigator.share){share.hidden=true;}else share.addEventListener('click',function(){navigator.share({title:@json($event->title),text:'Register and respond to '+@json($event->title),url:input.value});});
    }());
</script>
@endsection
