@extends('layout')
@section('title', 'Book payment · '.$pledge->invitee->name)
@section('content')
<main class="payment-booking-shell">
    <header class="payment-booking-head">
        <div>
            <a class="event-back" href="{{ route('events.pledges', $event) }}">← Back to pledges</a>
            <span class="eyebrow">Pledge collection</span>
            <h1>Book a payment</h1>
            <p>Record money received from {{ $pledge->invitee->name }}. This creates an auditable ledger entry.</p>
        </div>
        <div class="payment-person"><span>{{ strtoupper(mb_substr($pledge->invitee->name, 0, 1)) }}</span><div><small>Participant</small><strong>{{ $pledge->invitee->name }}</strong><em>{{ $pledge->invitee->email ?: $pledge->invitee->phone }}</em></div></div>
    </header>

    @include('partials.alerts')

    @php($paid = (float) $pledge->paid_amount)
    @php($balance = (float) $pledge->balance)
    <section class="payment-balance-grid" aria-label="Pledge balance">
        <article><span>Promised</span><strong>{{ $event->pledge_currency }} {{ number_format($pledge->amount, 2) }}</strong></article>
        <article><span>Collected</span><strong>{{ $event->pledge_currency }} {{ number_format($paid, 2) }}</strong></article>
        <article class="is-balance"><span>Outstanding</span><strong>{{ $event->pledge_currency }} {{ number_format(max(0, $balance), 2) }}</strong></article>
    </section>

    <div class="payment-booking-grid">
        <section class="payment-booking-card">
            <div class="payment-section-title"><span class="eyebrow">New ledger entry</span><h2>Payment details</h2><p>Reference and internal note are optional.</p></div>
            @if($pledge->status === 'cancelled')
                <div class="error">This pledge is cancelled. Restore it before recording a payment.</div>
            @else
                <form class="payment-page-form" method="post" action="{{ route('events.pledge-payments.store', [$event, $pledge]) }}">
                    @csrf
                    <div class="payment-primary-fields">
                        <div><label for="payment-amount">Amount received</label><div class="money-input"><span>{{ $event->pledge_currency }}</span><input id="payment-amount" type="number" name="amount" min="0.01" step="0.01" max="999999999999.99" value="{{ old('amount', max(0, $balance)) }}" required autofocus></div></div>
                        <div><label for="payment-date">Payment date</label><input id="payment-date" type="date" name="payment_date" value="{{ old('payment_date', now()->format('Y-m-d')) }}" max="{{ now()->format('Y-m-d') }}" required></div>
                    </div>
                    <div class="form-grid">
                        <div><label for="payment-method">Payment method</label><input id="payment-method" name="payment_method" maxlength="100" value="{{ old('payment_method') }}" placeholder="Cash, bank or mobile money"></div>
                        <div><label for="payment-reference">Reference <span class="muted">optional</span></label><input id="payment-reference" name="reference" maxlength="150" value="{{ old('reference') }}" placeholder="Transaction or receipt number"></div>
                    </div>
                    <label for="payment-note">Internal note <span class="muted">optional</span></label>
                    <textarea id="payment-note" name="note" maxlength="1000" placeholder="Visible to event administrators only">{{ old('note') }}</textarea>
                    <div class="overpayment-box"><label class="check-row"><input id="overpayment-override" type="checkbox" name="overpayment_override" value="1" @checked(old('overpayment_override'))><span><strong>Accept an overpayment</strong><small>Use only when the received amount intentionally exceeds the outstanding balance.</small></span></label><div id="overpayment-reason-wrap"><label for="overpayment-reason">Reason for overpayment</label><input id="overpayment-reason" name="overpayment_reason" maxlength="1000" value="{{ old('overpayment_reason') }}"></div></div>
                    <div class="payment-form-actions"><a class="btn secondary" href="{{ route('events.pledges', $event) }}">Cancel</a><button type="submit">Book payment →</button></div>
                </form>
            @endif
        </section>

        <aside class="payment-history-card">
            <div class="payment-section-title"><span class="eyebrow">Audit trail</span><h2>Payment history</h2></div>
            <div class="payment-page-history">
                @forelse($pledge->payments as $payment)
                    <article class="payment-page-entry {{ $payment->status === 'voided' ? 'is-voided' : '' }}"><div><strong>{{ $event->pledge_currency }} {{ number_format($payment->amount, 2) }}</strong><span>{{ $payment->payment_date->format('d M Y') }}</span></div><p>{{ $payment->payment_method ?: 'Method not specified' }}@if($payment->reference) · Ref {{ $payment->reference }}@endif</p>@if($payment->status === 'voided')<small>Voided: {{ $payment->void_reason }}</small>@else<span class="status-pill status-paid">Recorded</span>@endif</article>
                @empty
                    <div class="empty-payment-history"><strong>No payments yet</strong><p>The first booked payment will appear here.</p></div>
                @endforelse
            </div>
        </aside>
    </div>
</main>
<script>
    (function(){const box=document.getElementById('overpayment-override'),wrap=document.getElementById('overpayment-reason-wrap'),reason=document.getElementById('overpayment-reason');if(!box)return;function sync(){wrap.hidden=!box.checked;reason.required=box.checked;}box.addEventListener('change',sync);sync();}());
</script>
@endsection
