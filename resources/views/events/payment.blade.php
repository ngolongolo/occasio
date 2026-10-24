@extends('layout')
@section('title',($guest->participation_payment_status==='paid'?'Invitation':'Payment status').' · '.$event->title)
@section('content')
@php
    $paid = $guest->participation_payment_status === 'paid';
    $payment = $guest->latestParticipationPayment;
    $failed = in_array($payment?->status, ['request_failed','failed'], true);
    $manualPending = $guest->participation_payment_status === 'payment_reported';
    $displayCount = max(1, (int) $guest->attending_count);
    $total = $payment ? (float) $payment->amount : (float) $quote['total'];
    $displayCurrency = $payment?->currency ?: $quote['currency'];
    $displayUnitPrice = $payment ? $total / $displayCount : (float) $quote['unit_price'];
    $packageName = $quote['package_name'];
    $paymentDigits = preg_replace('/\D+/', '', (string) $payment?->msisdn);
    $maskedPaymentNumber = $paymentDigits ? 'ending '.substr($paymentDigits, -4) : null;
    $organiserUser=$event->user;
    $organiserSummary=$organiserUser?->organiserVerificationSummary()??[];
    $organiserIsVerified=filled($organiserSummary['badge_label']??null);
    $organiserVerificationLabel=$organiserSummary['badge_label']??'Identity verified';
    $organiserVerificationDescription=$organiserSummary['badge_description']??null;
    $organiserDisplayName=$organiserIsVerified&&filled($organiserSummary['public_name']??null)?$organiserSummary['public_name']:$event->host;
    $organiserPublicContact=$organiserIsVerified
        ? (($organiserSummary['public_contact_phone']??null)?:($organiserSummary['public_contact_email']??null)?:$event->contact)
        : $event->contact;
    $paymentRecipient=$event->participation_collection_method==='manual'?$organiserDisplayName.' (paid directly)':'Bluetick Pay for '.$organiserDisplayName;
    $reportEventUrl='mailto:'.(config('mail.from.address')?:'support@okesheni.com').'?subject='.rawurlencode('Report event '.$event->public_code);
@endphp
<main class="payment-focus-page {{ $paid?'is-confirmed':'' }}" style="--event-primary:{{ $event->primary_color ?: '#56283f' }};--event-background:{{ $event->background_color ?: '#fcf9f4' }}">
    <div class="payment-focus-topbar">
        <a href="{{ route('rsvp.show',$guest->token) }}" class="payment-focus-back"><span aria-hidden="true">←</span> Attendance details</a>
        <div class="payment-platform-brand"><img src="/assets/logo/logo_main.png" alt="Okesheni"><small>Securely powered by Okesheni</small></div>
    </div>

    @include('partials.alerts')

    <section class="payment-focus-card">
        <header class="payment-focus-event">
            <div class="payment-focus-event-identity">
                @if($event->logo_path)<img src="{{ route('rsvp.logo',$guest->token) }}" alt="{{ $organiserDisplayName }} logo">@endif
                <div>@include('partials.organiser-trust')<strong class="payment-focus-event-title">{{ $event->title }}</strong></div>
            </div>
            <span class="payment-focus-event-code">{{ $event->public_code }}</span>
        </header>
        <div class="payment-focus-meta"><span>{{ $event->starts_at->format('D, d M Y · g:i A') }}</span><span>{{ $event->venue }}</span></div>

        @if($paid)
            <div class="payment-focus-content payment-focus-pass">
                <div class="payment-focus-result-mark" aria-hidden="true">✓</div>
                <span class="eyebrow">Attendance and payment confirmed</span>
                <h1>Your invitation is ready</h1>
                <p>Keep this pass private and present its QR code at the entrance.</p>

                <figure class="payment-focus-ticket">
                    <span>Official access pass</span>
                    <img src="{{ route('rsvp.pass.qr',$guest->token) }}" alt="QR access pass for {{ $guest->name }}">
                    <figcaption><strong>{{ $guest->name }}</strong><small>{{ $packageName }} · {{ $guest->attending_count }} {{ $guest->attending_count===1?'admission':'admissions' }} · {{ $event->public_code }}</small></figcaption>
                </figure>

                <div class="payment-focus-actions"><a class="btn" href="{{ route('rsvp.pass.show',$guest->token) }}">Open access pass</a><a class="payment-focus-text-action" href="{{ route('rsvp.pass.qr',[$guest->token,'download'=>1]) }}">Download QR</a></div>
            </div>
        @else
            <div class="payment-focus-content">
                <div class="payment-focus-status {{ $failed?'is-failed':($manualPending?'is-manual':'') }}"><i aria-hidden="true"></i>{{ $manualPending?'Verification pending':($failed?'Action needed':'Payment pending') }}</div>
                <h1>{{ $manualPending?'Payment verification pending':($failed?'Payment request was not completed':'Complete the prompt on your phone') }}</h1>
                <p class="payment-focus-intro">{{ $manualPending?'The organiser has received your payment reference and will verify it shortly.':($failed?'Return to attendance details to confirm your mobile-money number, then try again.':'Approve the mobile-money request'.($maskedPaymentNumber?' sent to the number '.$maskedPaymentNumber:'').' to confirm your place.') }}</p>

                <div class="payment-focus-amount">
                    <div><span>Amount due</span><strong>{{ $displayCurrency }} {{ number_format($total,2) }}</strong><small>{{ $packageName }} · {{ $displayCurrency }} {{ number_format($displayUnitPrice,2) }} × {{ $displayCount }} {{ $displayCount===1?'person':'people' }}</small></div>
                    <div><span>Attendance</span><strong>{{ $displayCount }} {{ $displayCount===1?'person':'people' }}</strong></div>
                </div>

                <div class="payment-focus-state {{ $failed?'is-failed':'' }}" id="payment-state" data-status-url="{{ route('rsvp.payment.status',$guest->token) }}">
                    <span class="payment-focus-state-dot" aria-hidden="true"></span>
                    <div><strong>{{ $manualPending?'Organizer verification':($failed?'Payment request failed':'Waiting for payment confirmation') }}</strong><small id="payment-state-copy">{{ $manualPending?'No action is needed unless the organiser contacts you.':($failed?($payment?->provider_remarks?:'The payment request could not be completed.'):'Once approved, this page will display your QR invitation automatically.') }}</small></div>
                </div>

                <div class="payment-focus-actions">
                    @if($payment||$manualPending)<button class="check-payment-status btn" id="check-payment-status" type="button"><span aria-hidden="true">↻</span> Check payment status</button>@endif
                    @if($failed||(!$payment&&!$manualPending))<a class="payment-focus-text-action" href="{{ route('rsvp.show',$guest->token) }}">Update attendance details</a>@endif
                </div>
                <p class="payment-focus-note"><span aria-hidden="true">▣</span> Your QR pass is issued only after payment is confirmed.</p>
            </div>
        @endif

        <details class="payment-focus-disclosure">
            <summary>Payment and organiser details <span aria-hidden="true">＋</span></summary>
            <dl>
                @if($event->usesPackages())<div><dt>Package</dt><dd>{{ $packageName }}</dd></div>@endif
                <div><dt>Price</dt><dd>{{ $displayCurrency }} {{ number_format($displayUnitPrice,2) }} × {{ $displayCount }} = {{ $displayCurrency }} {{ number_format($total,2) }}</dd></div>
                <div><dt>Payment to</dt><dd>{{ $paymentRecipient }}</dd></div>
                <div><dt>Fees</dt><dd>Okesheni fee: {{ $event->participation_currency }} 0.00. Any mobile-money provider charges are separate unless shown in the total.</dd></div>
                <div><dt>Cancellation / refunds</dt><dd>{{ $event->cancellation_policy ?: 'No cancellation or refund policy was provided. Contact the organiser before committing to payment.' }}</dd></div>
                <div><dt>QR invitation</dt><dd>Issued only after payment is confirmed.</dd></div>
                @if($organiserPublicContact)<div><dt>Support</dt><dd>{{ $organiserPublicContact }}</dd></div>@endif
                <div><dt>Concerns</dt><dd><a class="checkout-report-link" href="{{ $reportEventUrl }}">Report this event</a></dd></div>
            </dl>
        </details>
    </section>
</main>

@if(!$paid&&($payment||$manualPending))
<script>
(function(){const state=document.getElementById('payment-state'),button=document.getElementById('check-payment-status'),copy=document.getElementById('payment-state-copy');if(!state)return;let checking=false;async function check(manual){if(checking)return;checking=true;if(button){button.disabled=true;button.classList.add('is-checking');}if(manual)copy.textContent='Checking the latest payment update…';try{const response=await fetch(state.dataset.statusUrl,{headers:{Accept:'application/json'},cache:'no-store'}),data=await response.json();if(!response.ok)throw new Error('Status request failed');if(data.confirmed){copy.textContent='Payment confirmed. Preparing your access pass…';location.replace(data.redirect);return;}copy.textContent=data.message||'Still waiting for confirmation.';}catch(e){copy.textContent='Could not check right now. Please try again.';}finally{checking=false;if(button){button.disabled=false;button.classList.remove('is-checking');}}}if(button)button.addEventListener('click',function(){check(true);});setInterval(function(){check(false);},8000);})();
</script>
@endif
@endsection
