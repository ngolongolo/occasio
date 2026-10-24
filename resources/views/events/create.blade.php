@extends('layout')
@section('title', __('ui.create.title').' · Okesheni')
@section('content')
@php
    $organiserVerified = auth()->user()->isVerifiedOrganiser();
    $canCollectOnline = auth()->user()->canCollectParticipationPayments();
    $organiserContact = auth()->user()->phone ?: auth()->user()->email;
    $paymentErrorFields = ['participation_payment_timing', 'participation_collection_method', 'participation_price', 'participation_currency', 'participation_payment_details', 'payout_payment_type', 'payout_number', 'cancellation_policy', 'pricing_mode', 'packages', 'packages.*'];
    $locationErrorFields = ['venue', 'venue_latitude', 'venue_longitude', 'dress_code', 'contact', 'what_to_bring', 'visibility', 'plus_ones_allowed', 'max_attendees_per_registration'];
    $initialCreateStep = $errors->hasAny($paymentErrorFields) ? 3 : ($errors->hasAny($locationErrorFields) ? 2 : 1);
@endphp
<main class="event-create-shell">
    <aside class="event-create-intro">
        <a class="create-back" href="/dashboard">← {{ __('ui.create.back') }}</a>
        <span class="eyebrow">{{ __('ui.create.new_event') }}</span>
        <h1>{{ __('ui.create.hero') }}</h1>
        <p>{{ __('ui.create.intro') }}</p>
        <ol class="create-steps" aria-label="{{ __('ui.create.title') }}">
            <li class="active" data-create-step-indicator="1"><span>01</span><button type="button" data-create-step-jump="1"><strong>{{ __('ui.create.details') }}</strong><small>{{ __('ui.create.details_small') }}</small></button></li>
            <li data-create-step-indicator="2"><span>02</span><button type="button" data-create-step-jump="2"><strong>{{ __('ui.create.design') }}</strong><small>{{ __('ui.create.design_small') }}</small></button></li>
            <li data-create-step-indicator="3"><span>03</span><button type="button" data-create-step-jump="3"><strong>{{ __('ui.create.delivery') }}</strong><small>{{ __('ui.create.delivery_small') }}</small></button></li>
        </ol>
        <div class="create-note"><span aria-hidden="true">✦</span><p><strong>{{ __('ui.create.nothing_sent') }}</strong><br>{{ __('ui.create.control') }}</p></div>
    </aside>

    <section class="event-create-card">
        <div class="create-card-header"><div><span class="eyebrow" id="create-step-count" data-template="{{ __('ui.create.step_count', ['current' => ':current', 'total' => ':total']) }}">{{ __('ui.create.step_count', ['current' => $initialCreateStep, 'total' => 3]) }}</span><h2>{{ __('ui.create.title') }}</h2></div><span class="required-note"><b>*</b> {{ __('ui.common.required_fields') }}</span></div>
        @include('partials.alerts')
        <form method="post" action="/events" class="event-create-form" enctype="multipart/form-data" data-initial-step="{{ $initialCreateStep }}" novalidate>
            @csrf
            <fieldset data-create-step-panel="1">
                <legend><span>01</span><div>{{ __('ui.create.identity') }}<small>{{ __('ui.create.identity_small') }}</small></div></legend>
                <div class="create-field full"><span class="field-label">{{ __('ui.create.occasion_type') }} <b>*</b></span><div class="occasion-type-options" role="radiogroup" aria-label="{{ __('ui.create.occasion_type') }}"><label><input type="radio" name="event_type" value="hangout" @checked(old('event_type',request()->query('event_type','hangout'))==='hangout') required><span class="occasion-type-icon" aria-hidden="true">✦</span><span><b>{{ __('ui.create.code') }}</b><small>{{ __('ui.create.code_small') }}</small></span><i aria-hidden="true">✓</i></label><label><input type="radio" name="event_type" value="event" @checked(old('event_type',request()->query('event_type'))==='event') required><span class="occasion-type-icon" aria-hidden="true">◇</span><span><b>{{ __('ui.create.professional') }}</b><small>{{ __('ui.create.professional_small') }}</small></span><i aria-hidden="true">✓</i></label></div></div>
                <div class="create-field full"><label for="event-title">{{ __('ui.create.event_title') }} <b>*</b></label><input id="event-title" name="title" value="{{ old('title') }}" placeholder="Annual Leadership Forum" maxlength="180" required autofocus><small>{{ __('ui.create.event_title_hint') }}</small></div>
                <div class="create-field full"><label for="event-host">{{ __('ui.create.host') }} <b>*</b></label><input id="event-host" name="host" value="{{ old('host', auth()->user()->name) }}" placeholder="BlueTick Events" maxlength="180" required><small>{{ __('ui.create.host_hint') }}</small></div>
                <div class="create-field full"><div class="label-row"><label for="event-description">{{ __('ui.create.message') }} <b>*</b></label><small id="description-count">0 / 5,000</small></div><textarea id="event-description" name="description" maxlength="5000" required>{{ old('description') }}</textarea></div>
                <div class="create-field full">
                    <label for="invitation_card">{{ __('ui.create.card') }}</label>
                    <div class="design-upload-zone">
                        <div class="upload-zone-icon" aria-hidden="true">▧</div>
                        <div><strong>{{ __('ui.create.card_add') }}</strong><p>{{ __('ui.create.card_formats') }}</p><small id="invitation-image-status" aria-live="polite">{{ __('ui.create.card_status') }}</small></div>
                        <label for="invitation_card">{{ __('ui.create.choose_picture') }}</label>
                        <input id="invitation_card" type="file" name="invitation_card" accept=".png,.jpg,.jpeg,image/png,image/jpeg">
                    </div>
                </div>
            </fieldset>

            <fieldset data-create-step-panel="1">
                <legend><span>02</span><div>{{ __('ui.create.schedule') }}<small>{{ __('ui.create.schedule_small') }}</small></div></legend>
                <div class="create-field"><label for="event-start">{{ __('ui.create.start') }} <b>*</b></label><input id="event-start" type="datetime-local" name="starts_at" value="{{ old('starts_at') }}" min="{{ now()->format('Y-m-d\TH:i') }}" required><small>{{ __('ui.create.timezone') }}</small></div>
                <div class="create-field"><label for="event-end">{{ __('ui.create.end') }}</label><input id="event-end" type="datetime-local" name="ends_at" value="{{ old('ends_at') }}"></div>
                <div class="create-field"><label for="event-deadline">{{ __('ui.create.deadline') }} <b>*</b></label><input id="event-deadline" type="date" name="rsvp_deadline" value="{{ old('rsvp_deadline') }}" min="{{ now()->format('Y-m-d') }}" required><small>{{ __('ui.create.deadline_hint') }}</small></div>
                <div class="create-field"><label for="event-capacity">{{ __('ui.create.capacity') }}</label><input id="event-capacity" type="number" name="capacity" min="1" max="100000" value="{{ old('capacity') }}"></div>
            </fieldset>

            <fieldset data-create-step-panel="2">
                <legend><span>03</span><div>{{ __('ui.create.location') }}<small>{{ __('ui.create.location_small') }}</small></div></legend>
                @include('events.partials.venue-picker', [
                    'inputId' => 'event-venue',
                    'wrapperClass' => 'create-field full',
                    'value' => old('venue'),
                    'latitude' => old('venue_latitude'),
                    'longitude' => old('venue_longitude'),
                    'source' => old('venue_source'),
                    'required' => true,
                ])
                <div class="create-field"><label for="event-dress-code">{{ __('ui.create.dress') }}</label><input id="event-dress-code" name="dress_code" value="{{ old('dress_code') }}" maxlength="180"></div>
                <div class="create-field"><label for="event-contact">{{ __('ui.create.contact') }}</label><input id="event-contact" name="contact" value="{{ old('contact', $organiserContact) }}" maxlength="100"><small>{{ __('ui.create.contact_hint') }}</small></div>
                <div class="create-field full"><label for="event-bring">{{ __('ui.create.bring') }}</label><textarea id="event-bring" name="what_to_bring" maxlength="2000">{{ old('what_to_bring') }}</textarea></div>
                <div class="create-field"><label for="event-visibility">{{ __('ui.create.visibility') }} <b>*</b></label><select id="event-visibility" name="visibility" required><option value="invite_only" @selected(old('visibility','invite_only')==='invite_only')>{{ __('ui.create.invited_only') }}</option><option value="link" @selected(old('visibility')==='link')>{{ __('ui.create.public_link') }}</option></select><small>{{ __('ui.create.visibility_hint') }}</small></div>
                <div class="create-field"><label><input type="checkbox" name="plus_ones_allowed" value="1" @checked(old('plus_ones_allowed'))> {{ __('ui.create.plus_ones') }}</label></div>
                <div class="create-field"><label for="max-attendees-per-registration">{{ __('ui.create.max_registration') }}</label><input id="max-attendees-per-registration" type="number" name="max_attendees_per_registration" min="1" max="10" value="{{ old('max_attendees_per_registration',2) }}"></div>
            </fieldset>

            <fieldset data-create-step-panel="3">
                <legend><span>04</span><div>{{ __('ui.create.cost') }}<small>{{ __('ui.create.cost_small') }}</small></div></legend>
                <div id="organiser-payment-access" class="create-field full organiser-payment-access {{ $organiserVerified ? 'is-verified' : '' }}" data-organiser-verified="{{ $organiserVerified ? '1' : '0' }}" aria-live="polite"><div><strong id="organiser-payment-access-title">{{ $organiserVerified ? 'Verified organiser' : 'Private paid events available' }}</strong><p id="organiser-payment-access-copy">{{ $organiserVerified ? ($canCollectOnline ? 'Your profile can configure manual or Bluetick Pay collections.' : 'You can configure manual or pay-at-event collection. Bluetick Pay unlocks after your payout account name is matched.') : 'Collect manually or at the occasion for invited guests. Verify your profile to publish a paid event or enable Bluetick Pay.' }}</p></div><a href="{{ route('organiser.verification.show') }}">{{ $organiserVerified ? 'View verification' : 'Apply for verification' }} →</a></div>
                <div class="create-field full"><label for="payment-timing">{{ __('ui.create.payment_option') }} <b>*</b></label><select id="payment-timing" name="participation_payment_timing" required><option value="free" @selected(old('participation_payment_timing','free')==='free')>{{ __('ui.create.free') }}</option><option value="pay_now" @selected(old('participation_payment_timing')==='pay_now')>{{ __('ui.create.pay_before') }}</option><option value="pay_at_event" @selected(old('participation_payment_timing')==='pay_at_event')>{{ __('ui.create.pay_at') }}</option></select></div>
                <div id="participation-payment-fields" class="create-field full" hidden>
                    @include('events.partials.package-pricing-fields')
                    <div id="collection-method-wrap"><label for="collection-method">How will you collect payment?</label><select id="collection-method" name="participation_collection_method"><option value="bluetick_pay" @selected(old('participation_collection_method',$canCollectOnline?'bluetick_pay':'manual')==='bluetick_pay') @disabled(!$canCollectOnline)>Bluetick Pay (recommended)</option><option value="manual" @selected(old('participation_collection_method',$canCollectOnline?'bluetick_pay':'manual')==='manual')>Collect manually</option></select><small>Bluetick Pay confirms mobile-money payments automatically after organiser and payout verification. Manual collection uses the payment details you provide, and guests see your current verification status.</small></div>
                    <div id="manual-payment-settings"><label for="participation-payment-details">Payment numbers and instructions</label><textarea id="participation-payment-details" name="participation_payment_details" maxlength="5000" placeholder="Mobile-money number, account name, or bank instructions">{{ old('participation_payment_details') }}</textarea></div>
                    <div id="bluetick-payout-settings"><div class="form-grid"><div><label for="payout-payment-type">Settlement account</label><select id="payout-payment-type" name="payout_payment_type"><option value="">Select account type</option>@foreach(['MixxByYas','Mpesa','AirtelMoney','Ecobank'] as $type)<option value="{{ $type }}" @selected(old('payout_payment_type')===$type)>{{ $type }}</option>@endforeach</select></div><div><label for="payout-number">Settlement number</label><input id="payout-number" name="payout_number" maxlength="50" value="{{ old('payout_number') }}" placeholder="0656414320"></div></div><small>Collections are confirmed through Bluetick Pay. Settlement is released to this account after reconciliation.</small></div>
                    <label for="cancellation-policy">Cancellation and refund policy</label><textarea id="cancellation-policy" name="cancellation_policy" maxlength="2000">{{ old('cancellation_policy') }}</textarea><small>Required for every paid event and shown before a guest confirms.</small>
                </div>
            </fieldset>

            <div class="create-form-actions">
                <a class="create-wizard-cancel" href="/dashboard">{{ __('ui.common.cancel') }}</a>
                <div class="create-wizard-buttons">
                    <button class="btn secondary" type="button" data-wizard-previous>← {{ __('ui.create.previous') }}</button>
                    <button type="button" data-wizard-next>{{ __('ui.create.continue') }} <span aria-hidden="true">→</span></button>
                    <button type="submit" data-wizard-submit>{{ __('ui.create.create_button') }} <span aria-hidden="true">→</span></button>
                </div>
            </div>
        </form>
    </section>
</main>
<script>
    (function(){
        const createForm=document.querySelector('.event-create-form'),description=document.getElementById('event-description'),count=document.getElementById('description-count');
        const stepPanels=[...createForm.querySelectorAll('[data-create-step-panel]')],stepIndicators=[...document.querySelectorAll('[data-create-step-indicator]')],stepCount=document.getElementById('create-step-count'),previousButton=createForm.querySelector('[data-wizard-previous]'),nextButton=createForm.querySelector('[data-wizard-next]'),submitButton=createForm.querySelector('[data-wizard-submit]');
        let currentStep=Math.max(1,Math.min(3,Number(createForm.dataset.initialStep)||1));
        function firstInvalidInStep(step){return stepPanels.filter(panel=>Number(panel.dataset.createStepPanel)===step).flatMap(panel=>[...panel.querySelectorAll('input,select,textarea')]).find(field=>!field.disabled&&!field.closest('[hidden]:not([data-create-step-panel])')&&!field.checkValidity());}
        function showStep(step,focus){currentStep=Math.max(1,Math.min(3,step));stepPanels.forEach(panel=>panel.hidden=Number(panel.dataset.createStepPanel)!==currentStep);stepIndicators.forEach(indicator=>{const number=Number(indicator.dataset.createStepIndicator);indicator.classList.toggle('active',number===currentStep);indicator.classList.toggle('is-complete',number<currentStep);const button=indicator.querySelector('button');button.setAttribute('aria-current',number===currentStep?'step':'false');});previousButton.hidden=currentStep===1;nextButton.hidden=currentStep===3;submitButton.hidden=currentStep!==3;stepCount.textContent=stepCount.dataset.template.replace(':current',currentStep).replace(':total','3');if(focus){document.querySelector('.event-create-card').scrollIntoView({behavior:'smooth',block:'start'});const heading=stepPanels.find(panel=>Number(panel.dataset.createStepPanel)===currentStep)?.querySelector('legend');if(heading){heading.setAttribute('tabindex','-1');window.setTimeout(()=>heading.focus({preventScroll:true}),250);}}}
        function validateStep(step){const invalid=firstInvalidInStep(step);if(!invalid)return true;showStep(step,false);invalid.reportValidity();invalid.focus({preventScroll:true});invalid.scrollIntoView({behavior:'smooth',block:'center'});return false;}
        function moveToStep(target){target=Math.max(1,Math.min(3,target));if(target>currentStep){for(let step=currentStep;step<target;step++){if(!validateStep(step))return;}}showStep(target,true);}
        createForm.classList.add('is-wizard-ready');showStep(currentStep,false);
        previousButton.addEventListener('click',()=>moveToStep(currentStep-1));nextButton.addEventListener('click',()=>moveToStep(currentStep+1));stepIndicators.forEach(indicator=>indicator.querySelector('button').addEventListener('click',()=>moveToStep(Number(indicator.dataset.createStepIndicator))));
        function updateCount(){count.textContent=description.value.length.toLocaleString()+' / 5,000';}
        description.addEventListener('input',updateCount);updateCount();
        const start=document.getElementById('event-start'),deadline=document.getElementById('event-deadline');
        start.addEventListener('change',function(){if(!start.value)return;const date=start.value.slice(0,10);deadline.max=date;if(!deadline.value)deadline.value=date;});
        if(start.value)deadline.max=start.value.slice(0,10);
        const timing=document.getElementById('payment-timing'),visibility=document.getElementById('event-visibility'),paymentFields=document.getElementById('participation-payment-fields'),price=document.getElementById('participation-price'),methodWrap=document.getElementById('collection-method-wrap'),method=document.getElementById('collection-method'),manual=document.getElementById('manual-payment-settings'),details=document.getElementById('participation-payment-details'),bluetick=document.getElementById('bluetick-payout-settings'),payoutType=document.getElementById('payout-payment-type'),payoutNumber=document.getElementById('payout-number'),contact=document.getElementById('event-contact'),policy=document.getElementById('cancellation-policy'),access=document.getElementById('organiser-payment-access'),accessTitle=document.getElementById('organiser-payment-access-title'),accessCopy=document.getElementById('organiser-payment-access-copy');
        function syncPayment(){const paid=timing.value!=='free',payNow=timing.value==='pay_now',isBluetick=payNow&&method.value==='bluetick_pay',publicPaid=paid&&visibility.value==='link';paymentFields.hidden=!paid;methodWrap.hidden=!payNow;manual.hidden=!payNow||isBluetick;bluetick.hidden=!isBluetick;if(window.syncParticipationPricing)window.syncParticipationPricing(paid);details.required=payNow&&!isBluetick;payoutType.required=isBluetick;payoutNumber.required=isBluetick;contact.required=paid;policy.required=paid;if(access.dataset.organiserVerified==='0'){access.classList.toggle('is-public-blocked',publicPaid);accessTitle.textContent=publicPaid?'Verification required for public paid events':'Private paid events available';accessCopy.textContent=publicPaid?'Choose invited guests only, or apply for organiser verification before publishing this paid event.':'Collect manually or at the occasion for invited guests. Verify your profile to publish a paid event or enable Bluetick Pay.';}}
        timing.addEventListener('change',syncPayment);visibility.addEventListener('change',syncPayment);method.addEventListener('change',syncPayment);syncPayment();
        const invitationImage=document.getElementById('invitation_card'),imageStatus=document.getElementById('invitation-image-status');let imageProcessing=false;
        invitationImage.addEventListener('change',async function(){const file=invitationImage.files[0];if(!file){imageStatus.textContent='Optional · maximum uploaded size after optimization: 2 MB.';return;}imageStatus.textContent='Checking image…';if(file.size<=1900000){imageStatus.textContent='Ready to upload · '+(file.size/1048576).toFixed(1)+' MB';return;}imageProcessing=true;createForm.querySelector('button[type="submit"]').disabled=true;try{const bitmap=await createImageBitmap(file),scale=Math.min(1,2400/Math.max(bitmap.width,bitmap.height)),canvas=document.createElement('canvas');canvas.width=Math.round(bitmap.width*scale);canvas.height=Math.round(bitmap.height*scale);const context=canvas.getContext('2d');context.fillStyle='#ffffff';context.fillRect(0,0,canvas.width,canvas.height);context.drawImage(bitmap,0,0,canvas.width,canvas.height);let quality=.88,blob;do{blob=await new Promise(resolve=>canvas.toBlob(resolve,'image/jpeg',quality));quality-=.08;}while(blob&&blob.size>1900000&&quality>=.44);if(!blob||blob.size>2048000)throw new Error('Image remains too large');const transfer=new DataTransfer(),name=file.name.replace(/\.[^.]+$/,'.jpg');transfer.items.add(new File([blob],name,{type:'image/jpeg',lastModified:Date.now()}));invitationImage.files=transfer.files;imageStatus.textContent='Optimized and ready · '+(blob.size/1048576).toFixed(1)+' MB';}catch(error){invitationImage.value='';imageStatus.textContent='This image could not be optimized. Please choose a smaller PNG or JPG.';}finally{imageProcessing=false;createForm.querySelector('button[type="submit"]').disabled=false;}});
        createForm.addEventListener('submit',function(event){for(let step=1;step<=3;step++){if(!validateStep(step)){event.preventDefault();return;}}if(imageProcessing){event.preventDefault();imageStatus.textContent='Please wait while the event card picture is optimized.';}});
    }());
</script>
@endsection
