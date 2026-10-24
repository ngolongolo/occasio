@extends('layout')
@section('title',$event->title.' · Your invitation')
@section('content')
@php
    $rsvpFields=$event->rsvpFields();
    $isPaid=$event->participation_payment_timing!=='free';
    $isPayNow=$event->participation_payment_timing==='pay_now';
    $isBluetick=$event->participation_collection_method==='bluetick_pay';
    $isManual=$event->participation_collection_method==='manual';
    $requiresApproval=$isPaid&&$event->rsvp_confirmation_mode==='organizer_approval';
    $paymentStage=$isPaid&&(!$requiresApproval||in_array($guest->confirmation_status,['awaiting_payment','confirmed'],true));
    $approvalRequestStage=$requiresApproval&&!$paymentStage;
    $paymentComplete=$guest->participation_payment_status==='paid';
    $latestPaymentState=$guest->latestParticipationPayment?->status;
    $paymentAttemptActive=in_array($latestPaymentState,['initiated','pending'],true);
    $manualPaymentReview=$guest->participation_payment_status==='payment_reported';
    $attendanceLocked=$paymentComplete||$paymentAttemptActive||$manualPaymentReview;
    $collectBluetickPayment=$paymentStage&&$isPayNow&&$isBluetick&&!$paymentComplete;
    $collectManualPayment=$paymentStage&&$isPayNow&&$isManual&&!$paymentComplete;
    $needsPaymentAcknowledgement=$isPaid&&!$paymentComplete&&(!$isPayNow||$paymentStage);
    $usesPackages=$isPaid&&$event->pricing_mode==='packages';
    $packageOptions=$packages??collect();
    $selectablePackageOptions=$packageOptions->filter(fn($package)=>(int)$guest->event_package_id===(int)$package->id||($package->is_active&&($package->available_places===null||$package->available_places>0)));
    $requestedPackageId=(int)($attendanceLocked?$guest->event_package_id:old('event_package_id',$guest->event_package_id?:0));
    $selectedPackageId=$requestedPackageId&&$selectablePackageOptions->contains('id',$requestedPackageId)
        ? $requestedPackageId
        : (int)optional($selectablePackageOptions->first())->id;
    $selectedPackage=$usesPackages?$packageOptions->firstWhere('id',$selectedPackageId):null;
    $usingStoredPackagePrice=$selectedPackage&&$guest->event_package_id===$selectedPackage->id&&$guest->package_unit_price_snapshot!==null;
    $initialCount=$attendanceLocked
        ? max(1,(int)$guest->attending_count)
        : max(1,min((int)old('attending_count',max(1,(int)$guest->attending_count)),(int)$guest->max_guests));
    $initialUnitPrice=$usingStoredPackagePrice?(float)$guest->package_unit_price_snapshot:($selectedPackage?(float)$selectedPackage->price:(float)$event->participation_price);
    $initialCurrency=$usingStoredPackagePrice?($guest->package_currency_snapshot?:$event->participation_currency):($selectedPackage?->currency?:$event->participation_currency);
    $initialPackageName=$usingStoredPackagePrice?($guest->package_name_snapshot?:$selectedPackage?->name):$selectedPackage?->name;
    $initialTotal=$initialUnitPrice*$initialCount;
    $organiserUser=$event->user;
    $organiserSummary=$organiserUser?->organiserVerificationSummary()??[];
    $organiserIsVerified=filled($organiserSummary['badge_label']??null);
    $organiserVerificationLabel=$organiserSummary['badge_label']??'Identity verified';
    $organiserVerificationDescription=$organiserSummary['badge_description']??null;
    $organiserDisplayName=$organiserIsVerified&&filled($organiserSummary['public_name']??null)?$organiserSummary['public_name']:$event->host;
    $organiserPublicContact=$organiserIsVerified
        ? (($organiserSummary['public_contact_phone']??null)?:($organiserSummary['public_contact_email']??null)?:$event->contact)
        : $event->contact;
    $paymentRecipient=$isManual
        ? $organiserDisplayName.' (paid directly)'
        : ($event->participation_payment_timing==='pay_at_event'?$organiserDisplayName.' (at the event)':'Bluetick Pay for '.$organiserDisplayName);
    $submitMode=$approvalRequestStage?'approval':($paymentComplete?'paid':($collectBluetickPayment?'bluetick':($collectManualPayment?'manual':($isPaid?'pay_at_event':'free'))));
    $reportEventUrl='mailto:'.(config('mail.from.address')?:'support@okesheni.com').'?subject='.rawurlencode('Report event '.$event->public_code);
@endphp
<main class="rsvp-shell">
    <div class="invitation">
        @if($event->invitation_card_path)
            @if($event->invitation_card_preview_path)
                <a href="{{ $guest->cardUrl() }}" target="_blank" rel="noopener"><img class="rsvp-card-image" src="{{ $guest->cardUrl(true) }}" alt="{{ $event->title }} invitation card"></a>
            @else
                <iframe class="rsvp-card-pdf" src="{{ $guest->cardUrl() }}" title="{{ $event->title }} invitation card"></iframe>
                <p><a href="{{ $guest->cardUrl() }}" target="_blank" rel="noopener">Open invitation card ↗</a></p>
            @endif
            <section class="invitation-art-details" aria-label="Event details">
                <span class="eyebrow">Invitation details</span>
                <h2>{{ $event->title }}</h2>
                <p style="white-space:pre-line">{{ $event->description }}</p>
                <dl>
                    <div><dt>Date and time</dt><dd>{{ $event->starts_at->format('d M Y, g:i A') }} EAT</dd></div>
                    <div><dt>Venue</dt><dd>{{ $event->venue }}</dd></div>
                    <div><dt>Respond by</dt><dd>{{ $event->rsvp_deadline->format('d M Y') }}</dd></div>
                    @if($organiserPublicContact)<div><dt>Organiser contact</dt><dd>{{ $organiserPublicContact }}</dd></div>@endif
                </dl>
            </section>
        @else
            @if($event->logo_path)<div class="rsvp-organisation"><img src="{{ route('rsvp.logo',$guest->token) }}" alt="{{ $organiserDisplayName }} logo"><span>Hosted by {{ $organiserDisplayName }}</span></div>@endif
            <span class="heart">♡</span>
            <p class="eyebrow">{{ $organiserDisplayName }}</p>
            <p>You are cordially invited</p>
            <h2>{{ $event->title }}</h2>
            <p>Dear {{ $guest->name }},</p>
            <p style="white-space:pre-line">{{ $event->description }}</p>
            <div class="invite-date"><strong style="font-size:22px">{{ $event->starts_at->format('d M Y') }}<br>{{ $event->starts_at->format('g:i A') }} EAT</strong></div>
            <strong>{{ $event->venue }}</strong>
            @if($event->dress_code)<p>{{ $event->dress_code }}</p>@endif
            <p>Respond by {{ $event->rsvp_deadline->format('d M Y') }}</p>
            @if($organiserPublicContact)<p>{{ $organiserPublicContact }}</p>@endif
        @endif
    </div>

    <div class="response-options">
        @include('partials.alerts')
        @if($closed)
            <section class="panel"><h2>Responses have closed</h2><p>Please contact the organiser: {{ $organiserPublicContact }}</p></section>
        @else
            @if($event->participation_enabled)
            <section class="panel rsvp-confirmation-card">
                @include('partials.organiser-trust')
                <span class="eyebrow">{{ $event->event_type==='hangout' ? 'Okesheni Code RSVP' : 'Professional Event RSVP' }}</span>
                <h2>{{ $isPaid?'Confirm your place':'Will you join us?' }}</h2>
                <p>This invitation is for {{ $guest->name }} and allows up to {{ $guest->max_guests }} {{ $guest->max_guests===1?'person':'people' }} in total.</p>

                @if($event->what_to_bring)<div class="notice"><strong>What to bring</strong><p style="white-space:pre-line">{{ $event->what_to_bring }}</p></div>@endif
                @if($guest->participation_payment_status==='payment_reported')<div class="notice"><strong>Payment submitted</strong><p>Your reference has been sent to the organiser for verification.</p></div>@endif
                @if($guest->confirmation_status==='awaiting_approval')<div class="notice rsvp-stage-notice"><strong>Attendance approval pending</strong><p>No payment is required until {{ $organiserDisplayName }} approves your request.</p></div>@endif
                @if($guest->confirmation_status==='awaiting_payment')<div class="notice rsvp-stage-notice is-ready"><strong>Attendance approved</strong><p>Review the amount below and complete payment to receive your QR invitation.</p></div>@endif
                @if($guest->rsvp_status==='accepted'&&!in_array($guest->confirmation_status,['confirmed','awaiting_approval','awaiting_payment'],true))<div class="notice"><strong>Confirmation pending</strong><p>Your QR access pass will appear after the organiser's confirmation requirements are complete.</p></div>@endif

                <form method="post" id="invited-rsvp-form">
                    @csrf
                    <input type="hidden" name="response_type" value="participation">
                    @if($isPaid)
                        <input type="hidden" name="rsvp_status" value="accepted">
                    @else
                        <label for="rsvp-status">Your response</label>
                        <select id="rsvp-status" name="rsvp_status" required><option value="accepted" @selected(old('rsvp_status',$guest->rsvp_status)==='accepted')>Joyfully accept</option><option value="declined" @selected(old('rsvp_status',$guest->rsvp_status)==='declined')>Regretfully decline</option></select>
                    @endif

                    <div id="attendance-fields">
                        @if($usesPackages)
                            <div class="event-package-selector rsvp-package-selector" id="rsvp-package-selector">
                                <div class="event-package-heading"><div><strong>Choose your package</strong><small>One package applies to everyone in this RSVP.</small></div><span>{{ $packageOptions->count() }} {{ Str::plural('option',$packageOptions->count()) }}</span></div>
                                @if($attendanceLocked)<input type="hidden" name="event_package_id" value="{{ $selectedPackageId }}">@endif
                                <div class="event-package-grid">
                                    @foreach($packageOptions as $package)
                                        @php
                                            $isCurrentPackage=$guest->event_package_id===$package->id;
                                            $displayPrice=$isCurrentPackage&&$guest->package_unit_price_snapshot!==null?(float)$guest->package_unit_price_snapshot:(float)$package->price;
                                            $displayCurrency=$isCurrentPackage&&$guest->package_currency_snapshot?$guest->package_currency_snapshot:$package->currency;
                                            $availablePlaces=$package->available_places;
                                            $packageMaximum=min((int)$guest->max_guests,(int)($package->max_per_registration?:$guest->max_guests),$availablePlaces===null?(int)$guest->max_guests:max(1,$availablePlaces));
                                            if($attendanceLocked&&$isCurrentPackage)$packageMaximum=max($packageMaximum,$initialCount);
                                            $packageUnavailable=(!$package->is_active||($availablePlaces!==null&&$availablePlaces<1))&&!$isCurrentPackage;
                                        @endphp
                                        <label class="event-package-option {{ $packageUnavailable?'is-unavailable':'' }} {{ !$package->is_active?'is-archived':'' }}" for="rsvp-package-{{ $package->id }}" data-package-card>
                                            <input id="rsvp-package-{{ $package->id }}" type="radio" name="event_package_id" value="{{ $package->id }}" data-package-name="{{ $isCurrentPackage&&$guest->package_name_snapshot?$guest->package_name_snapshot:$package->name }}" data-price="{{ $displayPrice }}" data-currency="{{ $displayCurrency }}" data-max="{{ $packageMaximum }}" @checked($selectedPackageId===$package->id) @disabled($attendanceLocked||$packageUnavailable) required>
                                            <span class="event-package-check" aria-hidden="true"></span>
                                            <span class="event-package-copy">
                                                <strong>{{ $isCurrentPackage&&$guest->package_name_snapshot?$guest->package_name_snapshot:$package->name }}</strong>
                                                @if($package->description)
                                                    <small>{{ $package->description }}</small>
                                                @endif
                                                <span>{{ $displayCurrency }} {{ number_format($displayPrice,2) }} <i>per person</i></span>
                                                @if(!$package->is_active)
                                                    <em>Archived · your saved selection</em>
                                                @elseif($package->max_per_registration||$availablePlaces!==null)
                                                    <em>
                                                        @if($package->max_per_registration) Up to {{ $package->max_per_registration }} per registration @endif
                                                        @if($package->max_per_registration&&$availablePlaces!==null) · @endif
                                                        @if($availablePlaces!==null) {{ $availablePlaces }} {{ Str::plural('place',$availablePlaces) }} available @endif
                                                    </em>
                                                @endif
                                            </span>
                                        </label>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                        <label for="rsvp-attending-count">Number attending, including you</label>
                        @if($attendanceLocked)<input type="hidden" name="attending_count" value="{{ $initialCount }}">@endif
                        <input id="rsvp-attending-count" type="number" @unless($attendanceLocked) name="attending_count" @endunless min="1" max="{{ $guest->max_guests }}" value="{{ $initialCount }}" required inputmode="numeric" @disabled($attendanceLocked)>
                        @if($attendanceLocked)<small>{{ $paymentComplete?'This paid registration is locked. Contact the organiser if it needs to change.':($manualPaymentReview?'Your package and party size are locked while the organiser verifies your payment reference.':'Your package and party size are locked while the current payment request is being processed.') }}</small>@endif

                        @if($rsvpFields['whatsapp']['enabled'])
                            <label for="rsvp-whatsapp">{{ $rsvpFields['whatsapp']['label'] }}</label>
                            <input id="rsvp-whatsapp" type="tel" name="responses[whatsapp]" value="{{ old('responses.whatsapp',data_get($guest->rsvp_answers,'whatsapp')) }}" placeholder="+255784311111" autocomplete="tel" @required($rsvpFields['whatsapp']['required'])>
                            <small>Include the country code.</small>
                        @endif
                        @if($rsvpFields['dietary']['enabled'])
                            <label for="rsvp-dietary">{{ $rsvpFields['dietary']['label'] }}</label>
                            <textarea id="rsvp-dietary" name="dietary" maxlength="1000" @required($rsvpFields['dietary']['required'])>{{ old('dietary',$guest->dietary) }}</textarea>
                        @endif
                        @foreach(['organization','job_title','accessibility','notes'] as $field)
                            @if($rsvpFields[$field]['enabled'])
                                <label for="rsvp-{{ $field }}">{{ $rsvpFields[$field]['label'] }}</label>
                                @if(in_array($field,['accessibility','notes']))
                                    <textarea id="rsvp-{{ $field }}" name="responses[{{ $field }}]" maxlength="1000" @required($rsvpFields[$field]['required'])>{{ old("responses.$field",data_get($guest->rsvp_answers,$field)) }}</textarea>
                                @else
                                    <input id="rsvp-{{ $field }}" name="responses[{{ $field }}]" value="{{ old("responses.$field",data_get($guest->rsvp_answers,$field)) }}" maxlength="180" @required($rsvpFields[$field]['required'])>
                                @endif
                            @endif
                        @endforeach

                        @if($collectManualPayment||$collectBluetickPayment)<div id="rsvp-payment-action-fields" class="rsvp-payment-action-fields">@endif
                        @if($collectManualPayment)
                            <div class="payment-instructions rsvp-payment-instructions"><span>Organiser's payment details</span><p style="white-space:pre-line">{{ $event->participation_payment_details }}</p></div>
                            <p class="manual-payment-warning"><strong>Direct payment</strong> You are paying the organiser directly. Okesheni cannot automatically verify, reverse or refund this payment.</p>
                            <label for="rsvp-payment-reference">Payment transaction/reference number</label>
                            <input id="rsvp-payment-reference" name="participation_payment_reference" maxlength="150" value="{{ old('participation_payment_reference',$guest->participation_payment_reference) }}" required>
                            <small>Pay using the instructions above, then enter the provider's confirmation reference.</small>
                        @elseif($collectBluetickPayment)
                            <label for="rsvp-payment-msisdn">Mobile-money number</label>
                            <input id="rsvp-payment-msisdn" name="payment_msisdn" inputmode="tel" autocomplete="tel" placeholder="0656414320" value="{{ old('payment_msisdn',$guest->phone) }}" required>
                            <small>A secure Snippe payment prompt will be sent only after you press the button below.</small>
                        @endif
                        @if($collectManualPayment||$collectBluetickPayment)</div>@endif

                        @if($isPaid)
                        <section class="checkout-review rsvp-checkout-review" aria-labelledby="rsvp-review-heading">
                            <header><div><span class="eyebrow">Review before confirming</span><h3 id="rsvp-review-heading">Your payment summary</h3></div><strong id="rsvp-review-total">{{ $initialCurrency }} {{ number_format($initialTotal,2) }}</strong></header>
                            <dl>
                                @if($usesPackages)<div><dt>Package</dt><dd id="rsvp-review-package">{{ $initialPackageName?:'—' }}</dd></div>@endif
                                <div><dt>Price</dt><dd><span id="rsvp-review-unit-price">{{ $initialCurrency }} {{ number_format($initialUnitPrice,2) }}</span> × <span id="rsvp-review-count">{{ $initialCount }} {{ $initialCount===1?'person':'people' }}</span></dd></div>
                                <div><dt>Payment to</dt><dd>{{ $approvalRequestStage?'After approval · ':'' }}{{ $paymentRecipient }}</dd></div>
                                <div><dt>Fees</dt><dd>Okesheni fee: {{ $event->participation_currency }} 0.00. Any mobile-money provider charges are separate unless shown in the total.</dd></div>
                                <div><dt>Cancellation / refunds</dt><dd>{{ $event->cancellation_policy ?: 'No cancellation or refund policy was provided. Contact the organiser before committing to payment.' }}</dd></div>
                                <div><dt>QR invitation</dt><dd>{{ $approvalRequestStage&&$isPayNow?'Available after organiser approval and confirmed payment.':($approvalRequestStage?'Issued after organiser approval; payment remains due at the event.':($isPayNow?'Issued after payment is confirmed.':'Issued after attendance is confirmed; payment remains due at the event.')) }}</dd></div>
                            </dl>
                            <footer>@if($organiserPublicContact)<span>Questions or refund requests: <strong>{{ $organiserPublicContact }}</strong></span>@endif<a class="checkout-report-link" href="{{ $reportEventUrl }}">Report this event</a></footer>
                        </section>
                        @if($needsPaymentAcknowledgement)
                            <label class="checkout-acknowledgement" id="rsvp-payment-acknowledgement-wrap" for="rsvp-payment-acknowledgement"><input id="rsvp-payment-acknowledgement" type="checkbox" name="checkout_acknowledged" value="1" required><span>I have reviewed the event, organiser, total amount and cancellation/refund policy.</span></label>
                        @endif
                        @endif
                    </div>

                    <button class="rsvp-submit" id="rsvp-submit" type="submit" data-mode="{{ $submitMode }}" data-price="{{ $initialUnitPrice }}" data-currency="{{ $initialCurrency }}" data-requires-approval="{{ $requiresApproval?1:0 }}" data-ack-on-approval="{{ !$isPayNow?1:0 }}" data-original-count="{{ max(1,(int)$guest->attending_count) }}" data-original-package="{{ $guest->event_package_id }}">
                        <span id="rsvp-submit-copy">
                            @if($submitMode==='approval')
                                {{ $guest->confirmation_status==='awaiting_approval'?'Update request for':'Request approval for' }} {{ $initialCount }} {{ $initialCount===1?'place':'places' }}
                            @elseif($submitMode==='bluetick')
                                Pay {{ $initialCurrency }} {{ number_format($initialTotal,2) }} · confirm {{ $initialCount }} {{ $initialCount===1?'place':'places' }}
                            @elseif($submitMode==='manual')
                                Submit {{ $initialCurrency }} {{ number_format($initialTotal,2) }} payment reference
                            @elseif($submitMode==='pay_at_event')
                                Confirm {{ $initialCount }} {{ $initialCount===1?'place':'places' }} · {{ $initialCurrency }} {{ number_format($initialTotal,2) }} due at event
                            @elseif($submitMode==='paid')
                                Save RSVP details
                            @else
                                Confirm participation
                            @endif
                        </span>
                        <b aria-hidden="true">→</b>
                    </button>
                    <small class="rsvp-submit-note" id="rsvp-submit-note" @if(!$approvalRequestStage&&!($isPayNow&&!$paymentComplete)) hidden @endif>{{ $approvalRequestStage?'No payment is requested until the organiser approves your attendance.':'Your QR pass is issued only after payment is confirmed.' }}</small>
                </form>

                @if($paymentComplete)<div class="notice"><strong>Payment confirmed</strong><p>Your payment is complete and your place is confirmed.</p></div>@endif
            </section>
            @endif

            @if($event->pledge_enabled)
            <section class="panel pledge-panel">
                <span class="eyebrow">Pledge</span>
                <h2 style="font-size:32px">Make a pledge</h2>
                @if($event->pledge_message)<p style="white-space:pre-line">{{ $event->pledge_message }}</p>@endif
                @if(in_array($guest->pledge?->status, ['paid','overpaid'], true))
                    <div class="notice"><strong>Payment confirmed</strong><p>Your pledge of {{ $event->pledge_currency }} {{ number_format($guest->pledge->amount,2) }} has been fully paid and verified by the organiser.</p></div>
                @else
                    <form method="post">@csrf<input type="hidden" name="response_type" value="pledge"><label>Pledge amount ({{ $event->pledge_currency }})</label><input type="number" name="amount" min="1" max="999999999999.99" step="0.01" value="{{ old('amount',$guest->pledge?->amount) }}" required><label>Payment method <span class="muted">(optional)</span></label><input name="payment_method" maxlength="100" value="{{ old('payment_method',$guest->pledge?->payment_method) }}" placeholder="Bank transfer or mobile money"><label>Transaction/reference number <span class="muted">(optional)</span></label><input name="payment_reference" maxlength="150" value="{{ old('payment_reference',$guest->pledge?->payment_reference) }}"><label>Message <span class="muted">(optional)</span></label><textarea name="message" maxlength="1000">{{ old('message',$guest->pledge?->message) }}</textarea><button style="margin-top:20px;width:100%">{{ $guest->pledge ? 'Update my pledge' : 'Submit pledge' }}</button></form>
                @endif
                @if($guest->pledge)<p><span class="tag">{{ str_replace('_',' ',$guest->pledge->status) }}</span></p>@endif
                <div class="payment-details"><h3>Payment details</h3><p style="white-space:pre-line">{{ $event->payment_details }}</p><p class="muted">Submitting a pledge does not confirm payment. The organiser will verify receipt.</p></div>
            </section>
            @endif

            @if($guest->accessPassEligible())
            <section class="access-pass-callout"><div><span class="eyebrow">Entry pass ready</span><h2>Your QR access pass</h2><p>@if($guest->package_name_snapshot)<strong>{{ $guest->package_name_snapshot }}</strong> · @endif Admits up to {{ $guest->attending_count }} {{ $guest->attending_count===1?'person':'people' }}. Present it at the entrance; each admitted person is counted.</p></div><a class="btn" href="{{ route('rsvp.pass.show',$guest->token) }}">View access pass →</a></section>
            @endif
            <p class="muted">You can update your response using this link until the event starts. Keep your personal invitation link private.</p>
        @endif
    </div>
</main>
<script>
(function(){
    const status=document.getElementById('rsvp-status'),fields=document.getElementById('attendance-fields'),count=document.getElementById('rsvp-attending-count'),reviewTotal=document.getElementById('rsvp-review-total'),reviewCount=document.getElementById('rsvp-review-count'),reviewPackage=document.getElementById('rsvp-review-package'),reviewUnit=document.getElementById('rsvp-review-unit-price'),submit=document.getElementById('rsvp-submit'),submitCopy=document.getElementById('rsvp-submit-copy'),paymentFields=document.getElementById('rsvp-payment-action-fields'),acknowledgement=document.getElementById('rsvp-payment-acknowledgement-wrap'),submitNote=document.getElementById('rsvp-submit-note'),packages=[...document.querySelectorAll('input[name="event_package_id"][type="radio"]')];
    if(!fields||!count)return;
    const formatter=new Intl.NumberFormat('en-TZ',{minimumFractionDigits:2,maximumFractionDigits:2});
    function sync(){
        const selected=packages.find(function(item){return item.checked;});
        const selectedMax=count.disabled?Math.max(1,Number(count.value)||1):(Number(selected&&selected.dataset.max)||Number(count.max)||1);
        count.max=String(selectedMax);
        const accepted=!status||status.value==='accepted',people=Math.max(1,Math.min(selectedMax,Number(count.value)||1)),places=people+' '+(people===1?'place':'places');
        count.value=people;fields.hidden=!accepted;
        if(status)fields.querySelectorAll('input,textarea,select').forEach(function(field){field.disabled=!accepted;});
        if(!submit||!submitCopy)return;
        const currency=(selected&&selected.dataset.currency)||submit.dataset.currency,price=Number((selected&&selected.dataset.price)||submit.dataset.price||0),total=currency+' '+formatter.format(price*people);
        submit.dataset.currency=currency;submit.dataset.price=String(price);
        const packageChanged=!!selected&&String(selected.value)!==String(submit.dataset.originalPackage||'');
        const needsNewApproval=accepted&&submit.dataset.requiresApproval==='1'&&submit.dataset.mode!=='approval'&&submit.dataset.mode!=='paid'&&(people!==Number(submit.dataset.originalCount)||packageChanged);
        if(paymentFields){paymentFields.hidden=needsNewApproval;paymentFields.querySelectorAll('input,textarea,select').forEach(function(field){field.disabled=needsNewApproval;field.required=!needsNewApproval;});}
        if(acknowledgement){const deferAcknowledgement=needsNewApproval&&submit.dataset.ackOnApproval!=='1';acknowledgement.hidden=deferAcknowledgement;const box=acknowledgement.querySelector('input');box.disabled=deferAcknowledgement;box.required=!deferAcknowledgement;}
        if(reviewTotal)reviewTotal.textContent=total;if(reviewCount)reviewCount.textContent=people+' '+(people===1?'person':'people');if(reviewPackage&&selected)reviewPackage.textContent=selected.dataset.packageName;if(reviewUnit)reviewUnit.textContent=currency+' '+formatter.format(price);
        if(!accepted){submitCopy.textContent='Send decline response';return;}
        if(needsNewApproval){submitCopy.textContent='Request approval for '+places;if(submitNote){submitNote.hidden=false;submitNote.textContent='Your party size changed. The organiser must approve it before payment.';}return;}
        if(submitNote){submitNote.hidden=!(submit.dataset.mode==='approval'||['bluetick','manual'].includes(submit.dataset.mode));submitNote.textContent=submit.dataset.mode==='approval'?'No payment is requested until the organiser approves your attendance.':'Your QR pass is issued only after payment is confirmed.';}
        if(submit.dataset.mode==='approval')submitCopy.textContent='{{ $guest->confirmation_status==='awaiting_approval'?'Update request for':'Request approval for' }} '+places;
        else if(submit.dataset.mode==='bluetick')submitCopy.textContent='Pay '+total+' · confirm '+places;
        else if(submit.dataset.mode==='manual')submitCopy.textContent='Submit '+total+' payment reference';
        else if(submit.dataset.mode==='pay_at_event')submitCopy.textContent='Confirm '+places+' · '+total+' due at event';
        else if(submit.dataset.mode==='paid')submitCopy.textContent='Save RSVP details';
        else submitCopy.textContent='Confirm '+places+' · free';
    }
    if(status)status.addEventListener('change',sync);count.addEventListener('input',sync);packages.forEach(function(item){item.addEventListener('change',sync);});sync();
}());
</script>
@endsection
