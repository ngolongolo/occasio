@extends('layout')
@section('title', $event->title.' · Event workspace')
@section('content')
<main class="event-workspace event-workspace-{{ $workspaceTab }}">
    @if($adminReadOnly ?? false)
        <aside class="admin-readonly-notice" role="status">
            <strong>System Admin inspection</strong>
            <span>You are viewing this organiser’s workspace in read-only mode. Use the dedicated admin controls for audited changes.</span>
            <a href="{{ route('admin.events') }}">Return to Event control</a>
        </aside>
    @endif
    <header class="event-hero">
        <div class="event-hero-copy">
            <a class="event-back" href="/dashboard">← All events</a>
            <span class="event-host">{{ $event->host }}</span>
            <h1>{{ $event->title }}</h1>
            <div class="event-facts">
                <span><b>Calendar</b>{{ $event->starts_at->format('D, d M Y · g:i A') }} EAT</span>
                <span><b>Location</b>{{ $event->venue }}</span>
                <span><b>Requested response date</b>{{ $event->rsvp_deadline->format('d M Y') }}</span>
            </div>
        </div>
        <div class="event-hero-actions">
            <div class="event-public-code"><span>Occasion code</span><div><strong id="event-public-code">{{ $event->public_code }}</strong><button type="button" id="copy-event-code" aria-label="Copy occasion code">Copy</button></div><small id="event-code-copy-status" aria-live="polite">{{ $event->visibility==='link' ? 'Guests can find this public occasion from the homepage.' : 'Visible to you; homepage lookup remains disabled for invite-only occasions.' }}</small></div>
            <a class="btn event-primary-action" href="{{ route('events.design', $event) }}">Customize invitation</a>
            @if($event->participation_enabled)<a class="btn event-quiet-action" href="{{ route('events.scanner',$event) }}">Open QR scanner</a>@endif
            <a class="btn event-quiet-action" href="{{ route('events.export', $event) }}">Export responses ↓</a>
        </div>
    </header>

    @include('partials.alerts')

    <div class="event-workspace-layout">
    <nav class="event-workspace-nav" aria-label="Event workspace">
        <a class="{{ $workspaceTab==='overview'?'is-active':'' }}" href="{{ route('events.show',$event) }}">
            <span class="workspace-nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="2"/><rect x="14" y="3" width="7" height="7" rx="2"/><rect x="3" y="14" width="7" height="7" rx="2"/><rect x="14" y="14" width="7" height="7" rx="2"/></svg></span>
            <span class="workspace-nav-copy">Overview<small>Event summary</small></span>
        </a>
        <a class="{{ $workspaceTab==='participants'?'is-active':'' }}" href="{{ route('events.participants',$event) }}">
            <span class="workspace-nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M16 20v-1.7a4.3 4.3 0 0 0-4.3-4.3H6.3A4.3 4.3 0 0 0 2 18.3V20"/><circle cx="9" cy="7" r="4"/><path d="M17 11a3.5 3.5 0 1 0 0-7M22 20v-1.5a4 4 0 0 0-3-3.9"/></svg></span>
            <span class="workspace-nav-copy">Invitations<small>Participants, RSVP &amp; attendance</small></span>
            <span class="workspace-nav-count" aria-label="{{ $stats['total'] }} participants">{{ $stats['total'] }}</span>
        </a>
        @if($event->participation_payment_timing!=='free')<a class="{{ $workspaceTab==='payments'?'is-active':'' }}" href="{{ route('events.payments',$event) }}">
            <span class="workspace-nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="2.5" y="5" width="19" height="14" rx="3"/><path d="M2.5 9.5h19M16.5 15h2"/></svg></span>
            <span class="workspace-nav-copy">Payments<small>Collections &amp; settlement</small></span>
            @if(($paymentSummary['pending']??0)>0)<span class="workspace-nav-count" aria-label="{{ $paymentSummary['pending'] }} pending payment requests">{{ $paymentSummary['pending'] }}</span>@endif
        </a>@endif
        @if($event->pledge_enabled)<a class="{{ $workspaceTab==='pledges'?'is-active':'' }}" href="{{ route('events.pledges',$event) }}">
            <span class="workspace-nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="2.5" y="5" width="19" height="14" rx="3"/><path d="M2.5 9.5h19M16.5 15h2"/></svg></span>
            <span class="workspace-nav-copy">Pledges<small>Payments &amp; balances</small></span>
        </a>@endif
        <a class="workspace-settings" href="{{ route('events.design',$event) }}">
            <span class="workspace-nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1-2.8 2.8-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.6v.2h-4V21a1.7 1.7 0 0 0-1-1.6 1.7 1.7 0 0 0-1.9.3l-.1.1L4.2 17l.1-.1a1.7 1.7 0 0 0 .3-1.9A1.7 1.7 0 0 0 3 14H2.8v-4H3a1.7 1.7 0 0 0 1.6-1 1.7 1.7 0 0 0-.3-1.9L4.2 7 7 4.2l.1.1a1.7 1.7 0 0 0 1.9.3A1.7 1.7 0 0 0 10 3v-.2h4V3a1.7 1.7 0 0 0 1 1.6 1.7 1.7 0 0 0 1.9-.3l.1-.1L19.8 7l-.1.1a1.7 1.7 0 0 0-.3 1.9 1.7 1.7 0 0 0 1.6 1h.2v4H21a1.7 1.7 0 0 0-1.6 1Z"/></svg></span>
            <span class="workspace-nav-copy">Settings<small>Design &amp; notifications</small></span>
        </a>
    </nav>
    <div class="event-workspace-content">

    @if($workspaceTab==='overview')
    <section class="event-metrics" aria-label="Event summary">
        <article><span>Invited</span><strong>{{ $stats['total'] }}</strong><small>Total participant records</small></article>
        @if($event->participation_enabled)
            <article><span>Confirmed</span><strong>{{ $stats['accepted'] }}</strong><small>{{ $stats['seats'] }} seats attending</small></article>
            <article><span>Declined</span><strong>{{ $stats['declined'] }}</strong><small>{{ max(0, $stats['total']-$stats['accepted']-$stats['declined']) }} awaiting response</small></article>
        @endif
        @if($event->pledge_enabled)
            <article><span>Total pledged</span><strong class="metric-money">{{ $event->pledge_currency }} {{ number_format($stats['pledged'], 2) }}</strong><small>All recorded pledges</small></article>
            <article><span>Total collected</span><strong class="metric-money">{{ $event->pledge_currency }} {{ number_format($stats['collected'], 2) }}</strong><small>{{ $stats['partially_paid'] }} partially paid</small></article>
            <article><span>Outstanding</span><strong class="metric-money">{{ $event->pledge_currency }} {{ number_format(max(0,$stats['pledged']-$stats['collected']), 2) }}</strong><small>Remaining pledge balance</small></article>
        @endif
        @if($event->responsesClosed())<article><span>Attended</span><strong>{{ $stats['attended'] }}</strong><small>{{ $stats['attended_seats'] }} people checked in</small></article>@endif
    </section>

    <section class="overview-grid">
        <article><span class="eyebrow">Next step</span><h2>Keep your event moving</h2><p>Add participants, deliver invitations, monitor RSVP responses and confirm attendance from one directory.</p><div class="actions"><a class="btn" href="{{ route('events.participants',$event) }}">Open participant directory</a></div></article>
        <article><span class="eyebrow">Event details</span><dl><div><dt>Host</dt><dd>{{ $event->host }}</dd></div><div><dt>Date</dt><dd>{{ $event->starts_at->format('d M Y, g:i A') }}</dd></div><div><dt>Venue</dt><dd>{{ $event->venue }}</dd></div><div><dt>Response date</dt><dd>{{ $event->rsvp_deadline->format('d M Y') }}</dd></div></dl></article>
    </section>
    @if($paymentSummary)
    <section class="overview-payment-summary">
        <header><div><span class="eyebrow">Participation payments</span><h2>Payment snapshot</h2><p>See collections at a glance, then open the payment workspace for requests and withdrawals.</p></div><a class="btn secondary" href="{{ route('events.payments',$event) }}">Open payments →</a></header>
        <div class="overview-payment-metrics"><article><span>Confirmed collections</span><strong>{{ $event->participation_currency }} {{ number_format($paymentSummary['collected'],2) }}</strong></article><article><span>Available to withdraw</span><strong>{{ $event->participation_currency }} {{ number_format($paymentSummary['available'],2) }}</strong></article><article><span>Pending</span><strong>{{ $paymentSummary['pending'] }}</strong></article><article><span>Failed</span><strong>{{ $paymentSummary['failed'] }}</strong></article></div>
    </section>
    @endif
    @endif

    @if($workspaceTab==='payments' && $paymentOperations)
    <section class="event-payments-panel payments-workspace-panel">
        <header class="event-payments-heading"><div><span class="eyebrow">Participation payments</span><h2>Collections and settlement</h2><p>Review payment requests, confirmations and withdrawals for this paid event.</p></div><span class="collection-method">{{ $isBluetickParticipation?'Bluetick Pay · Snippe':'Manual collection' }}</span></header>
        <div class="payment-operation-metrics"><article><span>Confirmed collections</span><strong>{{ $event->participation_currency }} {{ number_format($paymentOperations['collected'],2) }}</strong></article><article><span>Available to withdraw</span><strong>{{ $event->participation_currency }} {{ number_format($paymentOperations['available'],2) }}</strong></article><article><span>Pending requests</span><strong>{{ $paymentOperations['pending'] }}</strong></article><article><span>Failed requests</span><strong>{{ $paymentOperations['failed'] }}</strong></article></div>
        <div class="payment-operation-grid">
            <div class="payment-requests-section">
                <div class="payment-requests-title"><div><h3>Payment requests</h3><small>{{ $paymentOperations['paymentMatchCount'] }} {{ $paymentOperations['paymentMatchCount']===1?'request':'requests' }} found</small></div>@if($paymentOperations['paymentSearch']!==''||$paymentOperations['paymentStatus']!=='')<a href="{{ route('events.payments',$event) }}">Clear filters</a>@endif</div>
                <form class="payment-request-filters" method="get" action="{{ route('events.payments',$event) }}">
                    <label><span>Search payments</span><input type="search" name="payment_q" value="{{ $paymentOperations['paymentSearch'] }}" maxlength="100" placeholder="Participant, package, reference or phone"></label>
                    <label><span>Payment status</span><select name="payment_status"><option value="">All statuses</option><option value="pending" @selected($paymentOperations['paymentStatus']==='pending')>Pending</option><option value="paid" @selected($paymentOperations['paymentStatus']==='paid')>Paid</option><option value="failed" @selected($paymentOperations['paymentStatus']==='failed')>Failed</option>@if($isBluetickParticipation)<option value="superseded" @selected($paymentOperations['paymentStatus']==='superseded')>Superseded</option>@endif</select></label>
                    <button type="submit">Apply filters</button>
                </form>
                <div class="payment-request-list">
                    @if($isBluetickParticipation)
                        @forelse($paymentOperations['payments'] as $payment)
                            <article><div><strong>{{ $payment->invitee->name }}</strong><small>@if($payment->invitee->package_name_snapshot)<span class="payment-package-name">{{ $payment->invitee->package_name_snapshot }}</span> · @endif{{ $payment->reference }} · {{ $payment->created_at->format('d M Y, g:i A') }}</small></div><div><b>{{ $payment->currency }} {{ number_format($payment->amount,2) }}</b><span class="status-pill status-{{ $payment->status }}">{{ str_replace('_',' ',$payment->status) }}</span></div></article>
                        @empty
                            <p class="muted">{{ $paymentOperations['paymentSearch']!==''||$paymentOperations['paymentStatus']!==''?'No payment requests match these filters.':'No Bluetick Pay requests have been created yet.' }}</p>
                        @endforelse
                    @else
                        @forelse($paymentOperations['manualPayments'] as $guest)
                            @php($manualUnitPrice=(float)($guest->package_unit_price_snapshot??$event->participation_price??0))
                            <article><div><strong>{{ $guest->name }}</strong><small>@if($guest->package_name_snapshot)<span class="payment-package-name">{{ $guest->package_name_snapshot }}</span> · @endif{{ $guest->participation_payment_reference ?: 'No reference' }} · {{ $guest->responded_at?->format('d M Y, g:i A') }}</small></div><div><b>{{ $guest->package_currency_snapshot?:$event->participation_currency }} {{ number_format($manualUnitPrice*$guest->attending_count,2) }}</b><span class="status-pill status-{{ $guest->participation_payment_status }}">{{ str_replace('_',' ',$guest->participation_payment_status) }}</span></div></article>
                        @empty
                            <p class="muted">{{ $paymentOperations['paymentSearch']!==''||$paymentOperations['paymentStatus']!==''?'No payment requests match these filters.':'No manual payment submissions yet.' }}</p>
                        @endforelse
                    @endif
                </div>
            </div>
            <aside class="settlement-card">
                <span class="eyebrow">Settlement account</span>
                <h3>{{ $event->payout_payment_type ?: 'No default account' }}</h3>
                <p>{{ $event->payout_number ?: 'Choose another account below or add a default account in event settings.' }}</p>
                @if($isBluetickParticipation)
                @php($useOtherSettlement=old('use_other_account',!($event->payout_payment_type&&$event->payout_number)))
                <form class="settlement-withdrawal-form" method="post" action="{{ route('events.settlements.store',$event) }}" onsubmit="return confirm('Submit this withdrawal to the selected settlement account?')">
                    @csrf
                    <label for="settlement-amount">Withdrawal amount ({{ $event->participation_currency }})</label>
                    <input id="settlement-amount" type="number" name="amount" min="5000" max="{{ $paymentOperations['available'] }}" step="1" value="{{ old('amount',$paymentOperations['available']) }}" required>
                    <small>Snippe disbursements have a minimum of TZS 5,000.</small>
                    <label class="alternate-settlement-toggle" for="use-other-settlement"><input id="use-other-settlement" type="checkbox" name="use_other_account" value="1" @checked($useOtherSettlement)><span><b>Send to another account</b><small>Use a different destination for this withdrawal only.</small></span></label>
                    <div class="alternate-settlement-fields" id="alternate-settlement-fields" @if(!$useOtherSettlement) hidden @endif>
                        <label for="settlement-account-name">Account name</label>
                        <input id="settlement-account-name" name="account_name" maxlength="180" value="{{ old('account_name') }}" placeholder="Name registered on the account" @required($useOtherSettlement)>
                        <label for="settlement-payment-type">Account type</label>
                        <select id="settlement-payment-type" name="payment_type" @required($useOtherSettlement)><option value="">Select account type</option>@foreach(['MixxByYas'=>'Mixx by Yas','Mpesa'=>'M-Pesa','AirtelMoney'=>'Airtel Money','Ecobank'=>'Ecobank'] as $value=>$label)<option value="{{ $value }}" @selected(old('payment_type')===$value)>{{ $label }}</option>@endforeach</select>
                        <label for="settlement-payment-number">Phone or account number</label>
                        <input id="settlement-payment-number" name="payment_number" maxlength="50" value="{{ old('payment_number') }}" placeholder="0656414320" @required($useOtherSettlement)>
                        <small>This destination will be saved with the withdrawal record, not as the event default.</small>
                    </div>
                    <button type="submit" @disabled($paymentOperations['available']<5000)>Withdraw to selected account</button>
                </form>
                @endif
                <div class="settlement-history"><h4>Withdrawal history</h4>@forelse($paymentOperations['settlements'] as $settlement)@php($settlementNumber=preg_replace('/\s+/','',(string)$settlement->payment_number))<p><span><b>{{ $settlement->currency }} {{ number_format($settlement->amount,2) }}</b><small>{{ $settlement->created_at->format('d M Y, g:i A') }} · {{ $settlement->account_name ?: $event->host }} · {{ $settlement->payment_type }} · ••••{{ substr($settlementNumber,-4) }}</small></span><em class="status-pill status-{{ $settlement->status }}">{{ $settlement->status }}</em></p>@empty<p class="muted">No withdrawals requested.</p>@endforelse</div>
            </aside>
        </div>
    </section>
    @endif

    @if($workspaceTab==='participants')
    <section class="workspace-section">
        <div class="workspace-heading"><div><span class="eyebrow">Invitation delivery</span><h2>Invite and keep everyone informed</h2></div><p>Select participants in the directory below, then send their invitations.</p></div>
        <div class="event-operation-grid">
            @if($event->participation_enabled && !($event->event_type==='hangout' && $event->visibility==='invite_only'))
            <article class="operation-card operation-share">
                <div class="operation-icon" aria-hidden="true">↗</div>
                <div><span class="operation-step">01 · Share</span><h3>Open registration</h3><p>Anyone with this link can register or update their attendance.</p></div>
                <div class="share-link"><label for="registration-link">Public registration link</label><div><input id="registration-link" value="{{ $event->registrationUrl() }}" readonly><button type="button" id="copy-registration-link">Copy</button><button type="button" class="btn secondary" id="share-registration-link">Share</button></div><small id="copy-status" aria-live="polite"></small></div>
            </article>
            @endif
            <article class="operation-card">
                <div class="operation-icon" aria-hidden="true">✦</div>
                <div><span class="operation-step">{{ $event->participation_enabled ? '03' : '02' }} · Deliver</span><h3>Send invitations</h3><p>Select participants below, then choose how their personal invitation should be delivered.</p></div>
                <form id="send-invitations-form" method="post" action="{{ route('events.send', $event) }}">@csrf<div class="channel-options">@foreach(['email'=>'Email','whatsapp'=>'WhatsApp','sms'=>'SMS'] as $key=>$label)<label><input type="checkbox" name="channels[]" value="{{ $key }}"><span>{{ $label }}</span></label>@endforeach</div><label class="consent-row"><input type="checkbox" name="consent" value="1" required><span>I have permission to contact these guests.</span></label><button type="submit">Send selected invitations →</button></form>
            </article>
        </div>
    </section>
    @endif

    @if(in_array($workspaceTab,['participants','pledges'],true))
    <section class="workspace-section participant-section">
        @php($directoryCopy = ['participants'=>['Participant directory','Invitations, RSVP and attendance','Add participants, deliver invitations and follow each response from one place.'],'pledges'=>['Pledge ledger','Pledges and collections','Review commitments, book payments and monitor outstanding balances.']][$workspaceTab])
        <header class="directory-hero">
            <div class="directory-title"><span class="directory-kicker">{{ $directoryCopy[0] }}</span><h2>{{ $directoryCopy[1] }}</h2><p>{{ $directoryCopy[2] }}</p></div>
            @if($workspaceTab==='participants')<button class="directory-add-button" type="button" id="toggle-participant-form" aria-expanded="{{ $errors->any() ? 'true' : 'false' }}" aria-controls="quick-participant-form"><span>＋</span> Add participants</button>@endif
            <div class="directory-overview" aria-label="Participant response summary">
                <div><strong>{{ $stats['total'] }}</strong><span>All guests</span></div>
                @if($event->participation_enabled)<div><strong>{{ $stats['accepted'] }}</strong><span>Confirmed</span></div><div><strong>{{ max(0,$stats['total']-$stats['accepted']-$stats['declined']) }}</strong><span>Awaiting</span></div><div><strong>{{ $stats['declined'] }}</strong><span>Declined</span></div>@endif
            </div>
        </header>

        <div class="directory-body">

        @if($workspaceTab==='participants')
        <div class="participant-import-bar"><div><span class="operation-icon" aria-hidden="true">⇧</span><div><strong>Import participant list</strong><small>Upload an Excel or CSV file with up to 5,000 rows.</small></div></div><a class="text-action" href="{{ route('guest-template') }}">Download template</a><form method="post" action="{{ route('events.import',$event) }}" enctype="multipart/form-data">@csrf<input type="file" name="file" accept=".xlsx,.xls,.csv" aria-label="Participant spreadsheet" required><button type="submit">Import</button></form></div>
        @endif

        @if($workspaceTab==='participants')<div id="quick-participant-form" class="quick-participant-form" @if(!$errors->any()) hidden @endif>
            <div class="quick-form-intro"><span class="eyebrow">New participants</span><h3>Build your guest list</h3><p>Add up to 50 people at once. Each person needs an email address, phone number, or both.</p></div>
            <form method="post" action="{{ route('events.guests.store', $event) }}" id="participant-repeater-form">@csrf
                <div id="participant-rows" class="participant-repeater">@foreach(old('participants',[['name'=>'','email'=>'','phone'=>'','max_guests'=>1]]) as $index=>$participant)<fieldset class="participant-row" data-participant-row><legend>Participant <span>{{ $index+1 }}</span></legend><div><label>Full name</label><input name="participants[{{ $index }}][name]" value="{{ $participant['name']??'' }}" maxlength="150" autocomplete="name" required></div><div><label>Email address</label><input type="email" name="participants[{{ $index }}][email]" value="{{ $participant['email']??'' }}" maxlength="180" autocomplete="email"></div><div><label>Phone number</label><input type="tel" name="participants[{{ $index }}][phone]" value="{{ $participant['phone']??'' }}" placeholder="+255784311111" autocomplete="tel"></div><div><label>Maximum guests</label><input type="number" name="participants[{{ $index }}][max_guests]" value="{{ $participant['max_guests']??1 }}" min="1" max="10" required></div><button class="remove-participant" type="button" data-remove-participant aria-label="Remove participant">×</button></fieldset>@endforeach</div>
                <div class="participant-repeater-actions"><button class="btn secondary" type="button" id="add-participant-row">＋ Add another participant</button><button type="submit">Save participants</button></div>
            </form>
        </div>@endif

        @if($workspaceTab==='participants' && $event->participation_enabled && $event->responsesClosed())
        <div class="post-event-toolbar">
            <div><span class="eyebrow">Attendance confirmation</span><strong>Select participants below and confirm their attendance during or after the event.</strong></div>
            <form id="bulk-attendance-form" method="post" action="{{ route('events.attendance.bulk',$event) }}">@csrf @method('put')<select name="attendance_status" aria-label="Bulk attendance status"><option value="attended">Mark attended</option><option value="absent">Mark absent</option><option value="pending">Reset confirmation</option></select><input type="number" name="attended_count" min="1" max="10" value="1" aria-label="Default actual attendance count"><button type="submit">Confirm selected attendance</button></form>
            @if($event->attendance_thanks_enabled||$event->pledge_thanks_enabled)<form id="bulk-thanks-form" method="post" action="{{ route('events.thanks.bulk',$event) }}">@csrf<select name="type" aria-label="Thank-you type">@if($event->attendance_thanks_enabled)<option value="attendance_thanks">Attendance thanks</option>@endif @if($event->pledge_thanks_enabled)<option value="pledge_fully_paid_thanks">Fully-paid pledge thanks</option>@endif</select><button type="submit" class="btn secondary">Send selected thanks</button></form>@endif
        </div>
        @endif

        <div class="directory-toolbar-title"><div><strong>Participant list</strong><span class="guest-result-count">@if($invitees->total()) {{ $invitees->firstItem() }}–{{ $invitees->lastItem() }} of {{ $invitees->total() }} results @else No matching participants @endif</span></div><span>{{ $workspaceTab==='participants' ? 'Select people to send invitations'.($event->participation_enabled && $event->responsesClosed() ? ' or confirm attendance.' : '.') : 'Review each participant’s pledge and payment history.' }}</span></div>
        <form class="guest-filters" method="get" action="{{ request()->url() }}">
            <div class="filter-search"><label for="guest-search">Find a participant</label><div class="directory-search-input"><span aria-hidden="true">⌕</span><input id="guest-search" type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Search name, email or phone"></div></div>
            @if($workspaceTab==='participants'&&$event->participation_enabled)<div><label for="rsvp-filter">RSVP status</label><select id="rsvp-filter" name="rsvp"><option value="">All RSVP statuses</option>@foreach(['pending'=>'Pending','accepted'=>'Confirmed','declined'=>'Declined'] as $value=>$label)<option value="{{ $value }}" @selected(($filters['rsvp'] ?? '') === $value)>{{ $label }}</option>@endforeach</select></div>@endif
            @if($workspaceTab==='participants')<div><label for="delivery-filter">Invitation delivery</label><select id="delivery-filter" name="delivery"><option value="">All invitations</option>@foreach(['not_sent'=>'Not sent','queued'=>'Queued','previewed'=>'Previewed','submitted'=>'Submitted','failed'=>'Failed'] as $value=>$label)<option value="{{ $value }}" @selected(($filters['delivery'] ?? '') === $value)>{{ $label }}</option>@endforeach</select></div>@endif
            <div><label for="per-page-filter">Rows</label><select id="per-page-filter" name="per_page">@foreach([10,25,50,100] as $size)<option value="{{ $size }}" @selected((int) ($filters['per_page'] ?? 25) === $size)>{{ $size }}</option>@endforeach</select></div>
            <div class="filter-actions"><button type="submit">Apply</button><a class="btn secondary" href="{{ request()->url() }}">Reset</a></div>
        </form>

        <div class="table-wrap guest-table-wrap guest-table-shell"><table class="guest-table">
            <thead><tr>@if($workspaceTab==='participants')<th><input id="select-all-guests" type="checkbox" aria-label="Select all participants on this page"></th>@endif<th>Participant</th><th>Contact</th>@if($workspaceTab==='participants'&&$event->participation_enabled)<th>RSVP status</th><th>Seats</th>@endif @if($workspaceTab==='participants')<th>Invitation</th><th>Delivery</th>@endif @if($workspaceTab==='participants'&&$event->participation_enabled&&$event->responsesClosed())<th>Attendance</th>@endif @if($workspaceTab==='pledges')<th>Pledge</th>@endif @if($workspaceTab==='participants')<th><span class="sr-only">Actions</span></th>@endif</tr></thead>
            <tbody>@forelse($invitees as $guest)
                <tr>
                    @if($workspaceTab==='participants')<td class="guest-select"><input class="guest-selector" type="checkbox" name="guest_ids[]" value="{{ $guest->id }}" form="send-invitations-form" aria-label="Select {{ $guest->name }}"></td>@endif
                    <td class="guest-identity" data-label="Participant"><span class="guest-avatar" aria-hidden="true">{{ strtoupper(mb_substr($guest->name,0,1)) }}</span><div><strong>{{ $guest->name }}</strong>@if($guest->dietary)<small>{{ $guest->dietary }}</small>@endif</div></td>
                    <td data-label="Contact"><span class="contact-line">{{ $guest->email ?: '—' }}</span><span class="contact-line">{{ $guest->phone ?: '—' }}</span>@if(data_get($guest->rsvp_answers,'whatsapp'))<span class="contact-line">WhatsApp: {{ data_get($guest->rsvp_answers,'whatsapp') }}</span>@endif</td>
                    @if($workspaceTab==='participants'&&$event->participation_enabled)<td data-label="RSVP status"><span class="status-pill status-{{ $guest->rsvp_status }}">{{ $guest->rsvp_status }}</span><small class="muted" style="display:block">{{ str_replace('_',' ',$guest->confirmation_status) }}</small>@if($event->participation_payment_timing!=='free'&&$guest->rsvp_status==='accepted')<small class="muted" style="display:block">{{ str_replace('_',' ',$guest->participation_payment_status) }}@if($guest->participation_payment_reference) · {{ $guest->participation_payment_reference }}@endif</small>@if($event->participation_collection_method==='manual'&&in_array($guest->participation_payment_status,['payment_reported','rejected'],true))<form method="post" action="{{ route('events.participation-payments.update',[$event,$guest]) }}" style="display:flex;gap:5px;margin-top:6px">@csrf @method('put')<button class="mini-action" name="status" value="paid">Verify</button><button class="text-button danger-text" name="status" value="rejected">Reject</button></form>@endif @endif @if($guest->rsvp_status==='accepted'&&$event->rsvp_confirmation_mode==='organizer_approval'&&$guest->confirmation_status!=='confirmed')<form method="post" action="{{ route('events.rsvp-confirmations.update',[$event,$guest]) }}" style="display:flex;gap:5px;margin-top:6px">@csrf @method('put')<button class="mini-action" name="confirmation_status" value="confirmed">Approve</button><button class="text-button danger-text" name="confirmation_status" value="rejected">Reject</button></form>@endif</td><td data-label="Seats"><strong>{{ $guest->attending_count }}</strong> <span class="muted">/ {{ $guest->max_guests }}</span></td>@endif
                    @if($workspaceTab==='participants')<td data-label="Invitation"><a class="table-link" target="_blank" rel="noopener" href="{{ $guest->rsvpUrl() }}">Open ↗</a></td><td data-label="Delivery"><div class="delivery-list">@forelse($guest->deliveries as $d)<span class="delivery-state"><b>{{ $d->channel }}</b> {{ $d->status }}</span>@if($d->error)<small>{{ $d->error }}</small>@endif @empty<span class="muted">Not sent</span>@endforelse @foreach($guest->notificationDeliveries as $d)<span class="delivery-state"><b>{{ str_replace('_',' ',$d->type) }} {{ $d->channel }}</b> {{ $d->status }}</span>@endforeach</div></td>@endif
                    @if($workspaceTab==='participants'&&$event->participation_enabled&&$event->responsesClosed())<td data-label="Attendance" class="workflow-cell"><form method="post" action="{{ route('events.attendance.update',[$event,$guest]) }}">@csrf @method('put')<select name="attendance_status" aria-label="Attendance for {{ $guest->name }}">@foreach(['pending'=>'Not confirmed','attended'=>'Attended','absent'=>'Absent'] as $value=>$label)<option value="{{ $value }}" @selected($guest->attendance_status===$value)>{{ $label }}</option>@endforeach</select><input type="number" name="attended_count" min="0" max="{{ $guest->max_guests }}" value="{{ $guest->attended_count }}" aria-label="Actual attendees for {{ $guest->name }}"><button class="mini-action" type="submit">Save</button></form>@if($guest->attendance_status==='attended'&&$event->attendance_thanks_enabled)<form method="post" action="{{ route('events.thanks.send',[$event,$guest]) }}">@csrf<input type="hidden" name="type" value="attendance_thanks"><button class="text-button" type="submit">Send thanks</button></form>@endif</td>@endif
                    @if($workspaceTab==='pledges')<td data-label="Pledge" class="workflow-cell pledge-workflow">@if($guest->pledge)@php($paid=(float)$guest->pledge->paid_amount) @php($balance=(float)$guest->pledge->balance)<div class="pledge-summary"><strong>{{ $event->pledge_currency }} {{ number_format($guest->pledge->amount,2) }}</strong><span class="status-pill status-{{ $guest->pledge->status }}">{{ str_replace('_',' ',$guest->pledge->status) }}</span><small>Paid {{ $event->pledge_currency }} {{ number_format($paid,2) }} · Balance {{ $event->pledge_currency }} {{ number_format(max(0,$balance),2) }}</small><div class="progress-track"><i style="width:{{ min(100,$guest->pledge->amount>0?($paid/$guest->pledge->amount)*100:0) }}%"></i></div></div><a class="book-payment-link" href="{{ route('events.pledge-payments.create',[$event,$guest->pledge]) }}">＋ Book payment</a><details class="pledge-ledger"><summary>Payment history ({{ $guest->pledge->payments->where('status','recorded')->count() }})</summary><div class="payment-history">@forelse($guest->pledge->payments->sortByDesc('payment_date') as $payment)<article class="payment-entry {{ $payment->status==='voided'?'is-voided':'' }}"><strong>{{ $event->pledge_currency }} {{ number_format($payment->amount,2) }}</strong><span>{{ $payment->payment_date->format('d M Y') }} · {{ $payment->payment_method ?: 'Method not specified' }}</span>@if($payment->reference)<small>Ref: {{ $payment->reference }}</small>@endif @if($payment->status==='recorded')<div class="payment-actions">@if($event->payment_receipt_enabled)<form method="post" action="{{ route('events.pledge-payments.receipt',[$event,$guest->pledge,$payment]) }}">@csrf<button class="text-button" type="submit">Send receipt</button></form>@endif<form method="post" action="{{ route('events.pledge-payments.void',[$event,$guest->pledge,$payment]) }}">@csrf @method('put')<input name="void_reason" required maxlength="1000" placeholder="Reason for correction"><button class="text-button danger-text" type="submit">Void</button></form></div>@else<small>Voided: {{ $payment->void_reason }}</small>@endif</article>@empty<p class="muted">No payments booked.</p>@endforelse</div></details>@if(in_array($guest->pledge->status,['paid','overpaid'])&&$event->pledge_thanks_enabled)<form method="post" action="{{ route('events.thanks.send',[$event,$guest]) }}">@csrf<input type="hidden" name="type" value="pledge_fully_paid_thanks"><button class="text-button" type="submit">Send fully-paid thanks</button></form>@endif @else<span class="muted">No pledge</span>@endif</td>@endif
                    @if($workspaceTab==='participants')<td class="guest-actions"><div class="row-actions">@if($guest->accessPassEligible())<form method="post" action="{{ route('events.access-pass.regenerate',[$event,$guest]) }}" onsubmit="return confirm('Regenerate this pass? The previous QR code will stop working.')">@csrf @method('put')<button class="icon-btn" type="submit" aria-label="Regenerate access pass for {{ $guest->name }}" title="Regenerate QR access pass">▦</button></form>@endif<a class="icon-btn" href="{{ route('events.guests.edit', [$event, $guest]) }}" aria-label="Edit {{ $guest->name }}" title="Edit participant">✎</a><form method="post" action="{{ route('events.guests.destroy', [$event, $guest]) }}" onsubmit="return confirm('Delete this participant from the guest list?')">@csrf @method('delete')<button class="icon-btn danger" type="submit" aria-label="Delete {{ $guest->name }}" title="Delete participant">×</button></form></div></td>@endif
                </tr>
            @empty<tr><td colspan="{{ 6 + ($event->participation_enabled ? 2 : 0) + ($event->pledge_enabled ? 1 : 0) + ($event->responsesClosed() ? 1 : 0) }}" class="empty-participants"><strong>No participants found</strong><span>Add someone above or clear the current filters.</span></td></tr>@endforelse</tbody>
        </table></div>
        {{ $invitees->links('partials.pagination') }}
        </div>
    </section>
    @endif
    </div>
    </div>
</main>
<script>
    (function () {
        const adminReadOnly = @json((bool) ($adminReadOnly ?? false));
        const eventCode=document.getElementById('event-public-code'),copyEventCode=document.getElementById('copy-event-code'),eventCodeStatus=document.getElementById('event-code-copy-status');
        if(copyEventCode)copyEventCode.addEventListener('click',async function(){try{await navigator.clipboard.writeText(eventCode.textContent.trim());}catch(error){const range=document.createRange();range.selectNodeContents(eventCode);const selection=window.getSelection();selection.removeAllRanges();selection.addRange(range);document.execCommand('copy');selection.removeAllRanges();}eventCodeStatus.textContent='Occasion code copied.';setTimeout(function(){eventCodeStatus.textContent=@json($event->visibility==='link' ? 'Guests can find this public occasion from the homepage.' : 'Visible to you; homepage lookup remains disabled for invite-only occasions.');},3000);});
        const form=document.getElementById('quick-participant-form'),toggle=document.getElementById('toggle-participant-form'),rows=document.getElementById('participant-rows'),addRow=document.getElementById('add-participant-row');
        if(toggle)toggle.addEventListener('click',function(){const opening=form.hidden;form.hidden=!opening;toggle.setAttribute('aria-expanded',opening?'true':'false');if(opening)form.querySelector('input').focus();});
        function renumberParticipants(){if(!rows)return;rows.querySelectorAll('[data-participant-row]').forEach(function(row,index){row.querySelector('legend span').textContent=index+1;row.querySelectorAll('[name]').forEach(function(input){input.name=input.name.replace(/participants\[\d+\]/,'participants['+index+']');});row.querySelector('[data-remove-participant]').hidden=rows.children.length===1;});}
        if(addRow)addRow.addEventListener('click',function(){if(rows.children.length>=50)return;const index=rows.children.length,row=document.createElement('fieldset');row.className='participant-row';row.dataset.participantRow='';row.innerHTML='<legend>Participant <span>'+(index+1)+'</span></legend><div><label>Full name</label><input name="participants['+index+'][name]" maxlength="150" autocomplete="name" required></div><div><label>Email address</label><input type="email" name="participants['+index+'][email]" maxlength="180" autocomplete="email"></div><div><label>Phone number</label><input type="tel" name="participants['+index+'][phone]" placeholder="+255784311111" autocomplete="tel"></div><div><label>Maximum guests</label><input type="number" name="participants['+index+'][max_guests]" value="1" min="1" max="10" required></div><button class="remove-participant" type="button" data-remove-participant aria-label="Remove participant">×</button>';rows.appendChild(row);renumberParticipants();row.querySelector('input').focus();});
        if(rows)rows.addEventListener('click',function(event){const button=event.target.closest('[data-remove-participant]');if(!button||rows.children.length===1)return;button.closest('[data-participant-row]').remove();renumberParticipants();});renumberParticipants();
        ['bulk-attendance-form','bulk-thanks-form'].forEach(function(id){const bulk=document.getElementById(id);if(!bulk)return;bulk.addEventListener('submit',function(event){bulk.querySelectorAll('input[data-selected-guest]').forEach(function(input){input.remove();});const selected=document.querySelectorAll('.guest-selector:checked');if(!selected.length){event.preventDefault();alert('Select at least one participant.');return;}selected.forEach(function(box){const input=document.createElement('input');input.type='hidden';input.name='guest_ids[]';input.value=box.value;input.dataset.selectedGuest='1';bulk.appendChild(input);});});});
        const alternateSettlement=document.getElementById('use-other-settlement'),alternateFields=document.getElementById('alternate-settlement-fields');
        if(alternateSettlement&&alternateFields){const alternateInputs=alternateFields.querySelectorAll('select,input');const syncAlternateSettlement=function(){alternateFields.hidden=!alternateSettlement.checked;alternateInputs.forEach(function(field){field.disabled=!alternateSettlement.checked;field.required=alternateSettlement.checked;});};alternateSettlement.addEventListener('change',syncAlternateSettlement);syncAlternateSettlement();}
        if (adminReadOnly) {
            document.querySelectorAll('.event-workspace form').forEach(function (form) {
                if ((form.getAttribute('method') || 'get').toLowerCase() === 'get') return;
                form.querySelectorAll('input, select, textarea, button').forEach(function (control) { control.disabled = true; });
            });
        }
        const input=document.getElementById('registration-link'),copy=document.getElementById('copy-registration-link'),share=document.getElementById('share-registration-link'),status=document.getElementById('copy-status');
        if(!input)return;
        copy.addEventListener('click',async function(){try{await navigator.clipboard.writeText(input.value);}catch(error){input.select();document.execCommand('copy');}status.textContent='Link copied to clipboard.';setTimeout(function(){status.textContent='';},3000);});
        if(!navigator.share){share.hidden=true;}else share.addEventListener('click',function(){navigator.share({title:@json($event->title),text:'Register and respond to '+@json($event->title),url:input.value});});
    }());
</script>
@endsection
