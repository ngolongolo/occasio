@extends('layout')
@section('title', 'Register for '.$event->title.' · Okesheni')
@section('content')
@php
    $rsvpFields=$event->rsvpFields();
    $isPaid=$event->participation_payment_timing!=='free';
    $isPayNow=$event->participation_payment_timing==='pay_now';
    $approvalFirst=$isPaid&&$event->rsvp_confirmation_mode==='organizer_approval';
    $needsPaymentAcknowledgement=$isPaid&&(!$approvalFirst||!$isPayNow);
    $isBluetickPayNow=$isPayNow&&$event->participation_collection_method==='bluetick_pay';
    $isManualPayNow=$isPayNow&&$event->participation_collection_method==='manual';
    $useContactAsPayment=$isBluetickPayNow&&!$approvalFirst&&$rsvpFields['phone']['enabled'];
    $usesPackages=$isPaid&&$event->pricing_mode==='packages';
    $packageOptions=$packages??collect();
    $availablePackageOptions=$packageOptions->filter(fn($package)=>$package->available_places===null||$package->available_places>0);
    $requestedPackageId=(int)old('event_package_id',0);
    $selectedPackageId=$requestedPackageId&&$availablePackageOptions->contains('id',$requestedPackageId)
        ? $requestedPackageId
        : (int)optional($availablePackageOptions->first())->id;
    $selectedPackage=$usesPackages?$packageOptions->firstWhere('id',$selectedPackageId):null;
    $initialCount=max(1,min((int)old('attending_count',1),$partyLimit));
    $initialUnitPrice=$selectedPackage?(float)$selectedPackage->price:(float)$event->participation_price;
    $initialCurrency=$selectedPackage?->currency?:$event->participation_currency;
    $initialTotal=$initialUnitPrice*$initialCount;
    $extraFields=['whatsapp','dietary','organization','job_title','accessibility','notes'];
    $hasExtra=collect($extraFields)->contains(fn($field)=>$rsvpFields[$field]['enabled']);
    $hasRequiredExtra=collect($extraFields)->contains(fn($field)=>$rsvpFields[$field]['enabled']&&$rsvpFields[$field]['required']);
    $organiserUser=$event->user;
    $organiserSummary=$organiserUser?->organiserVerificationSummary()??[];
    $organiserIsVerified=filled($organiserSummary['badge_label']??null);
    $organiserVerificationLabel=$organiserSummary['badge_label']??'Identity verified';
    $organiserVerificationDescription=$organiserSummary['badge_description']??null;
    $organiserDisplayName=$organiserIsVerified&&filled($organiserSummary['public_name']??null)?$organiserSummary['public_name']:$event->host;
    $organiserPublicContact=$organiserIsVerified
        ? (($organiserSummary['public_contact_phone']??null)?:($organiserSummary['public_contact_email']??null)?:$event->contact)
        : $event->contact;
    $paymentRecipient=$event->participation_collection_method==='manual'
        ? $organiserDisplayName.' (paid directly)'
        : ($event->participation_payment_timing==='pay_at_event'?$organiserDisplayName.' (at the event)':'Bluetick Pay for '.$organiserDisplayName);
    $reportEventUrl='mailto:'.(config('mail.from.address')?:'support@okesheni.com').'?subject='.rawurlencode('Report event '.$event->public_code);
@endphp
<main class="public-registration join-simple-shell" style="--event-primary:{{ $event->primary_color ?: '#56283f' }};--event-background:{{ $event->background_color ?: '#fcf9f4' }};--event-text:{{ $event->text_color ?: '#56283f' }}">
    <section class="registration-event join-simple-event">
        <div class="join-event-topline"><span class="attendance-live-mark"><i></i>{{ $event->visibility==='link'?__('ui.rsvp.open'):__('ui.rsvp.private') }}</span><span class="attendance-event-code">{{ $event->public_code }}</span></div>
        @if($event->invitation_card_path)
            <figure class="registration-invitation-card join-invitation-art"><img src="{{ route('events.registration.card', ['token' => $event->public_code, 'v' => substr(sha1($event->invitation_card_path), 0, 12)]) }}" alt="{{ $event->title }} invitation"></figure>
        @endif
        <div class="join-event-content">
            <div class="registration-organisation join-organisation">@if($event->logo_path)<img src="{{ route('events.registration.logo',$event->public_code) }}" alt="{{ $organiserDisplayName }} logo">@endif @include('partials.organiser-trust',['onDark'=>true])</div>
            <h1>{{ $event->title }}</h1>
            <div class="join-event-facts">
                <span><small>{{ __('ui.rsvp.when') }}</small><strong>{{ $event->starts_at->format('D, d M Y · g:i A') }}</strong></span>
                <span><small>{{ __('ui.rsvp.where') }}</small><strong>{{ $event->venue }}</strong></span>
                <span><small>{{ __('ui.rsvp.respond_by') }}</small><strong>{{ $event->rsvp_deadline->format('d M Y') }}</strong></span>
            </div>
            <details class="join-event-more"><summary>{{ __('ui.rsvp.event_details') }}</summary><div class="registration-description-wrap"><p class="registration-description" id="registration-description">{{ $event->description }}</p><button class="registration-description-toggle" id="registration-description-toggle" type="button" aria-controls="registration-description" aria-expanded="false" hidden>{{ __('ui.rsvp.read_more') }}</button></div>@if($event->dress_code||$event->what_to_bring)<div class="join-guidance">@if($event->dress_code)<p><small>{{ __('ui.rsvp.dress') }}</small><strong>{{ $event->dress_code }}</strong></p>@endif @if($event->what_to_bring)<p><small>{{ __('ui.rsvp.bring') }}</small><strong>{{ $event->what_to_bring }}</strong></p>@endif</div>@endif</details>
        </div>
    </section>

    <section class="panel registration-form join-simple-card">
        <header class="join-form-heading"><div><span class="eyebrow">{{ $isPaid?__('ui.rsvp.attendance_payment'):__('ui.rsvp.attendance_registration') }}</span><h2>{{ __('ui.rsvp.confirm_place') }}</h2><p>{{ $approvalFirst?'Request the organiser’s approval first. Payment follows only if approved.':($isBluetickPayNow?'Review the total, then receive a secure mobile-money prompt.':($isManualPayNow?'Review the payment details and submit your reference.':'Share the details needed for your place.')) }}</p></div>@if($isPaid)<div class="join-unit-price"><strong id="registration-unit-price">{{ $initialCurrency }} {{ number_format($initialUnitPrice,2) }}</strong><small>{{ $usesPackages?'selected package · per person':'per person' }}</small></div>@endif</header>

        @if($closed || $soldOut)
            <div class="attendance-unavailable"><span aria-hidden="true">!</span><div><strong>{{ $closed?'Registration has closed':'This event is currently full' }}</strong><p>{{ $closed?'The event has already started.':'The maximum attendance has been reached.' }}@if($event->contact) Contact {{ $event->contact }} for assistance.@endif</p></div></div>
        @else
            <form method="post" class="join-form" id="attendance-registration-form">
                @csrf
                <fieldset class="join-form-group"><legend>{{ __('ui.rsvp.your_details') }}</legend>
                    <label for="registration-name">{{ __('ui.rsvp.full_name') }}</label><input id="registration-name" name="name" value="{{ old('name') }}" maxlength="150" required autocomplete="name" placeholder="{{ __('ui.rsvp.name_placeholder') }}">
                    <div class="form-grid">@if($rsvpFields['email']['enabled'])<div><label for="registration-email">{{ $rsvpFields['email']['label'] }}</label><input id="registration-email" type="email" name="email" value="{{ old('email') }}" maxlength="180" autocomplete="email" placeholder="you@example.com" @required($rsvpFields['email']['required']||!$rsvpFields['phone']['enabled'])></div>@endif @if($rsvpFields['phone']['enabled'])<div><label for="registration-phone">{{ $useContactAsPayment?'Phone & mobile-money number':$rsvpFields['phone']['label'] }}</label><input id="registration-phone" type="tel" name="phone" value="{{ old('phone') }}" placeholder="+255 784 311 111" autocomplete="tel" @required($useContactAsPayment||$rsvpFields['phone']['required']||!$rsvpFields['email']['enabled'])>@if($useContactAsPayment)<small>The payment prompt will be sent to this number.</small>@endif</div>@endif</div>
                </fieldset>

                <fieldset class="join-form-group"><legend>{{ __('ui.rsvp.attendance') }}</legend>
                    @if(!$isPaid)<label for="registration-status">{{ __('ui.rsvp.will_attend') }}</label><select id="registration-status" name="rsvp_status" required><option value="accepted" @selected(old('rsvp_status','accepted')==='accepted')>{{ __('ui.rsvp.yes') }}</option><option value="declined" @selected(old('rsvp_status')==='declined')>{{ __('ui.rsvp.no') }}</option></select>@else<input type="hidden" name="rsvp_status" value="accepted">@endif
                    <div id="registration-attendance-fields">
                        @if($usesPackages)
                            <div class="event-package-selector" id="registration-package-selector">
                                <div class="event-package-heading"><div><strong>Choose your package</strong><small>Prices shown are per person.</small></div><span>{{ $packageOptions->count() }} {{ Str::plural('option',$packageOptions->count()) }}</span></div>
                                <div class="event-package-grid">
                                    @foreach($packageOptions as $package)
                                        @php
                                            $availablePlaces=$package->available_places;
                                            $packageMaximum=min($partyLimit,(int)($package->max_per_registration?:$partyLimit),$availablePlaces===null?$partyLimit:max(1,$availablePlaces));
                                            $packageUnavailable=$availablePlaces!==null&&$availablePlaces<1;
                                        @endphp
                                        <label class="event-package-option {{ $packageUnavailable?'is-unavailable':'' }}" for="registration-package-{{ $package->id }}" data-package-card>
                                            <input id="registration-package-{{ $package->id }}" type="radio" name="event_package_id" value="{{ $package->id }}" data-package-name="{{ $package->name }}" data-price="{{ $package->price }}" data-currency="{{ $package->currency }}" data-max="{{ $packageMaximum }}" @checked($selectedPackageId===$package->id) @disabled($packageUnavailable) required>
                                            <span class="event-package-check" aria-hidden="true"></span>
                                            <span class="event-package-copy">
                                                <strong>{{ $package->name }}</strong>
                                                @if($package->description)
                                                    <small>{{ $package->description }}</small>
                                                @endif
                                                <span>
                                                    @if($packageUnavailable)
                                                        Sold out
                                                    @else
                                                        {{ $package->currency }} {{ number_format($package->price,2) }} <i>per person</i>
                                                    @endif
                                                </span>
                                                @if(!$packageUnavailable&&($package->max_per_registration||$availablePlaces!==null))
                                                    <em>
                                                        @if($package->max_per_registration) Up to {{ $package->max_per_registration }} per registration @endif
                                                        @if($package->max_per_registration&&$availablePlaces!==null) · @endif
                                                        @if($availablePlaces!==null) {{ $availablePlaces }} {{ Str::plural('place',$availablePlaces) }} left @endif
                                                    </em>
                                                @endif
                                            </span>
                                        </label>
                                    @endforeach
                                </div>
                                @if($packageOptions->isEmpty())<div class="event-package-empty"><strong>No packages are available</strong><span>Please contact the organiser before registering.</span></div>@endif
                            </div>
                        @endif
                        <div class="join-attendance-row">
                            <div><label for="registration-count">{{ __('ui.rsvp.number_attending') }}</label><small>You can register up to {{ $partyLimit }} {{ trans_choice('ui.rsvp.person',$partyLimit) }}@if($remainingCapacity!==null) · {{ $remainingCapacity }} {{ trans_choice('ui.rsvp.place',$remainingCapacity) }} remaining @endif</small><div class="attendance-stepper"><button type="button" id="attendance-minus" aria-label="Remove one attendee">−</button><input id="registration-count" type="number" name="attending_count" min="1" max="{{ $partyLimit }}" value="{{ $initialCount }}" required inputmode="numeric"><button type="button" id="attendance-plus" aria-label="Add one attendee">+</button></div></div>
                            @if($isPaid)<div class="registration-total join-total" id="registration-total" data-price="{{ $initialUnitPrice }}" data-currency="{{ $initialCurrency }}"><span>{{ __('ui.rsvp.total') }}</span><strong id="registration-total-amount">{{ $initialCurrency }} {{ number_format($initialTotal,2) }}</strong></div>@endif
                        </div>
                    </div>
                </fieldset>

                @if(!$approvalFirst&&$isBluetickPayNow&&!$useContactAsPayment)
                <fieldset class="join-form-group"><legend>Payment number</legend><label for="registration-payment-msisdn">Mobile-money number</label><input id="registration-payment-msisdn" type="tel" name="payment_msisdn" value="{{ old('payment_msisdn') }}" inputmode="tel" autocomplete="tel" placeholder="0656414320" required><small>Mixx by Yas, Airtel Money, M-Pesa and HaloPesa are supported.</small></fieldset>
                @elseif(!$approvalFirst&&$isManualPayNow)
                <fieldset class="join-form-group"><legend>Payment reference</legend><div class="payment-instructions"><span>Organiser’s payment details</span><p style="white-space:pre-line">{{ $event->participation_payment_details }}</p></div><p class="manual-payment-warning"><strong>Direct payment</strong> You are paying the organiser directly. Okesheni cannot automatically verify, reverse or refund this payment.</p><label for="registration-payment-reference">Transaction/reference number</label><input id="registration-payment-reference" name="participation_payment_reference" value="{{ old('participation_payment_reference') }}" maxlength="150" required></fieldset>
                @endif

                @if($hasExtra)
                <details class="join-extra" id="registration-preferences" @if($hasRequiredExtra||$errors->has('dietary')||$errors->has('responses.*')) open @endif><summary>{{ __('ui.rsvp.additional') }} <small>{{ $hasRequiredExtra?__('ui.common.required_fields'):__('ui.common.optional') }}</small></summary><div>
                    @if($rsvpFields['whatsapp']['enabled'])<label for="registration-whatsapp">{{ $rsvpFields['whatsapp']['label'] }}</label><input id="registration-whatsapp" type="tel" name="responses[whatsapp]" value="{{ old('responses.whatsapp') }}" placeholder="+255 784 311 111" autocomplete="tel" @required($rsvpFields['whatsapp']['required'])>@endif
                    @if($rsvpFields['dietary']['enabled'])<label for="registration-dietary">{{ $rsvpFields['dietary']['label'] }}</label><textarea id="registration-dietary" name="dietary" maxlength="1000" placeholder="Allergies or meal preferences" @required($rsvpFields['dietary']['required'])>{{ old('dietary') }}</textarea>@endif
                    @foreach(['organization','job_title','accessibility','notes'] as $field)@if($rsvpFields[$field]['enabled'])<label for="registration-{{ $field }}">{{ $rsvpFields[$field]['label'] }}</label>@if(in_array($field,['accessibility','notes']))<textarea id="registration-{{ $field }}" name="responses[{{ $field }}]" maxlength="1000" @required($rsvpFields[$field]['required'])>{{ old("responses.$field") }}</textarea>@else<input id="registration-{{ $field }}" name="responses[{{ $field }}]" value="{{ old("responses.$field") }}" maxlength="180" @required($rsvpFields[$field]['required'])>@endif @endif @endforeach
                </div></details>
                @endif

                @if($isPaid)
                <section class="checkout-review" aria-labelledby="registration-review-heading">
                    <header><div><span class="eyebrow">{{ __('ui.rsvp.review') }}</span><h3 id="registration-review-heading">{{ __('ui.rsvp.payment_summary') }}</h3></div><strong id="registration-review-total">{{ $initialCurrency }} {{ number_format($initialTotal,2) }}</strong></header>
                    <dl>
                        @if($usesPackages)<div><dt>{{ __('ui.rsvp.package') }}</dt><dd id="registration-review-package">{{ $selectedPackage?->name?:'—' }}</dd></div>@endif
                        <div><dt>{{ __('ui.rsvp.price') }}</dt><dd><span id="registration-review-unit-price">{{ $initialCurrency }} {{ number_format($initialUnitPrice,2) }}</span> × <span id="registration-review-count">{{ $initialCount }} {{ trans_choice('ui.rsvp.person',$initialCount) }}</span></dd></div>
                        <div><dt>{{ __('ui.rsvp.payment_to') }}</dt><dd>{{ $paymentRecipient }}</dd></div>
                        <div><dt>{{ __('ui.rsvp.fees') }}</dt><dd>Okesheni fee: {{ $event->participation_currency }} 0.00. Any mobile-money provider charges are separate unless shown in the total.</dd></div>
                        <div><dt>{{ __('ui.rsvp.refunds') }}</dt><dd>{{ $event->cancellation_policy ?: 'No cancellation or refund policy was provided. Contact the organiser before committing to payment.' }}</dd></div>
                        <div><dt>{{ __('ui.rsvp.qr') }}</dt><dd>{{ $approvalFirst&&$isPayNow?'Available after organiser approval and confirmed payment.':($approvalFirst?'Issued after organiser approval; payment remains due at the event.':($isPayNow?'Issued after payment is confirmed.':'Issued after attendance is confirmed; payment remains due at the event.')) }}</dd></div>
                    </dl>
                    <footer>@if($organiserPublicContact)<span>Questions or refund requests: <strong>{{ $organiserPublicContact }}</strong></span>@endif<a class="checkout-report-link" href="{{ $reportEventUrl }}">{{ __('ui.rsvp.report') }}</a></footer>
                </section>
                @if($needsPaymentAcknowledgement)
                <label class="checkout-acknowledgement" for="registration-payment-acknowledgement"><input id="registration-payment-acknowledgement" type="checkbox" name="checkout_acknowledged" value="1" required><span>I have reviewed the event, organiser, total amount and cancellation/refund policy.</span></label>
                @endif
                @endif

                <div class="join-submit"><button id="registration-submit" type="submit" data-paid="{{ $isPaid?1:0 }}" data-approval-first="{{ $approvalFirst?1:0 }}" data-payment-timing="{{ $event->participation_payment_timing }}" data-collection-method="{{ $event->participation_collection_method }}" data-price="{{ $initialUnitPrice }}" data-currency="{{ $initialCurrency }}" @disabled($usesPackages&&$packageOptions->isEmpty())><span id="registration-submit-copy">{{ $approvalFirst?'Request approval for '.$initialCount.' '.($initialCount===1?'place':'places'):($isBluetickPayNow?'Pay '.$initialCurrency.' '.number_format($initialTotal,2).' · confirm '.$initialCount.' '.($initialCount===1?'place':'places'):($isManualPayNow?'Submit '.$initialCurrency.' '.number_format($initialTotal,2).' payment reference':($isPaid?'Confirm '.$initialCount.' '.($initialCount===1?'place':'places').' · '.$initialCurrency.' '.number_format($initialTotal,2).' due at event':'Submit RSVP'))) }}</span> <b aria-hidden="true">→</b></button><small>{{ $approvalFirst?'No payment is requested until the organiser approves your attendance.':($isPayNow?'Your QR pass is issued after payment confirmation.':'You can update your response before the event starts.') }}</small></div>
            </form>
        @endif
        @if($organiserPublicContact)<footer class="join-help"><span>Need help?</span><strong>{{ $organiserPublicContact }}</strong></footer>@endif
    </section>
</main>
<script>
(function(){
    const description=document.getElementById('registration-description'),toggle=document.getElementById('registration-description-toggle');
    if(description&&toggle&&description.scrollHeight>description.clientHeight+1){toggle.hidden=false;toggle.addEventListener('click',function(){const expanded=description.classList.toggle('is-expanded');toggle.textContent=expanded?'Show less':'Read more';toggle.setAttribute('aria-expanded',expanded?'true':'false');});}

    const count=document.getElementById('registration-count'),minus=document.getElementById('attendance-minus'),plus=document.getElementById('attendance-plus'),summary=document.getElementById('registration-total'),amount=document.getElementById('registration-total-amount'),reviewAmount=document.getElementById('registration-review-total'),reviewCount=document.getElementById('registration-review-count'),reviewPackage=document.getElementById('registration-review-package'),reviewUnit=document.getElementById('registration-review-unit-price'),unitPrice=document.getElementById('registration-unit-price'),submit=document.getElementById('registration-submit'),submitCopy=document.getElementById('registration-submit-copy'),packages=[...document.querySelectorAll('input[name="event_package_id"]')];
    if(count){const min=Number(count.min)||1,baseMax=Number(count.max)||1,formatter=new Intl.NumberFormat('en-TZ',{minimumFractionDigits:2,maximumFractionDigits:2});function sync(){const selected=packages.find(function(item){return item.checked;});const max=Math.max(min,Math.min(baseMax,Number(selected&&selected.dataset.max)||baseMax));count.max=String(max);const people=Math.max(min,Math.min(max,Number(count.value)||min));count.value=people;if(minus)minus.disabled=people<=min;if(plus)plus.disabled=people>=max;const currency=(selected&&selected.dataset.currency)||(summary&&summary.dataset.currency)||(submit&&submit.dataset.currency)||'TZS',price=Number((selected&&selected.dataset.price)||(summary&&summary.dataset.price)||(submit&&submit.dataset.price)||0),total=currency+' '+formatter.format(price*people),places=people+' '+(people===1?'place':'places'),unit=currency+' '+formatter.format(price);if(summary){summary.dataset.currency=currency;summary.dataset.price=String(price);}if(submit){submit.dataset.currency=currency;submit.dataset.price=String(price);}if(summary&&amount)amount.textContent=total;if(reviewAmount)reviewAmount.textContent=total;if(reviewCount)reviewCount.textContent=people+' '+(people===1?'person':'people');if(reviewPackage&&selected)reviewPackage.textContent=selected.dataset.packageName;if(reviewUnit)reviewUnit.textContent=unit;if(unitPrice)unitPrice.textContent=unit;if(submit&&submitCopy&&submit.dataset.paid==='1'){if(submit.dataset.approvalFirst==='1')submitCopy.textContent='Request approval for '+places;else if(submit.dataset.paymentTiming==='pay_now'&&submit.dataset.collectionMethod==='bluetick_pay')submitCopy.textContent='Pay '+total+' · confirm '+places;else if(submit.dataset.paymentTiming==='pay_now')submitCopy.textContent='Submit '+total+' payment reference';else submitCopy.textContent='Confirm '+places+' · '+total+' due at event';}}if(minus)minus.addEventListener('click',function(){count.value=Number(count.value)-1;sync();});if(plus)plus.addEventListener('click',function(){count.value=Number(count.value)+1;sync();});count.addEventListener('input',sync);packages.forEach(function(item){item.addEventListener('change',sync);});sync();}

    const status=document.getElementById('registration-status'),attendance=document.getElementById('registration-attendance-fields'),preferences=document.getElementById('registration-preferences');
    if(status&&attendance){const toggleAttendance=function(){const attending=status.value==='accepted';attendance.hidden=!attending;if(preferences)preferences.hidden=!attending;attendance.querySelectorAll('input,textarea,select').forEach(function(field){field.disabled=!attending;});if(preferences)preferences.querySelectorAll('input,textarea,select').forEach(function(field){field.disabled=!attending;});};status.addEventListener('change',toggleAttendance);toggleAttendance();}
}());
</script>
@endsection
