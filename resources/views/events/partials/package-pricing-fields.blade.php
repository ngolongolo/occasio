@php
    $pricingEvent = $event ?? null;
    $pricingMode = old('pricing_mode', $pricingEvent?->pricing_mode ?: 'single');
    $storedPackages = $pricingEvent
        ? $pricingEvent->packages()->orderBy('sort_order')->orderBy('id')->get()->map(fn ($package) => [
            'id' => $package->id,
            'name' => $package->name,
            'description' => $package->description,
            'price' => $package->price,
            'capacity' => $package->capacity,
            'max_per_registration' => $package->max_per_registration,
            'is_active' => $package->is_active,
        ])->all()
        : [];
    $packageRows = old('packages', $storedPackages ?: [[
        'name' => 'General admission',
        'description' => '',
        'price' => '',
        'capacity' => '',
        'max_per_registration' => '',
        'is_active' => true,
    ]]);
@endphp

<div class="package-pricing-builder" data-package-pricing data-paid="0">
    <div class="pricing-mode-heading">
        <div><span class="field-label">Pricing structure</span><small>Charge one price or let participants choose a package.</small></div>
        <div class="pricing-mode-options" role="radiogroup" aria-label="Pricing structure">
            <label><input type="radio" name="pricing_mode" value="single" @checked($pricingMode === 'single')><span><b>Single price</b><small>One price for every participant</small></span></label>
            <label><input type="radio" name="pricing_mode" value="packages" @checked($pricingMode === 'packages')><span><b>Pricing packages</b><small>General, VIP, Premium and more</small></span></label>
        </div>
    </div>

    <div class="pricing-currency-row">
        <label for="participation-currency">Event currency</label>
        <input id="participation-currency" name="participation_currency" maxlength="3" value="{{ old('participation_currency', $pricingEvent?->participation_currency ?: 'TZS') }}" autocomplete="off">
        <small>One currency applies to every package and payment for this event.</small>
    </div>

    <div class="single-pricing-fields" data-single-pricing>
        <label for="participation-price">Price per person</label>
        <input id="participation-price" type="number" name="participation_price" min="0.01" step="0.01" value="{{ old('participation_price', $pricingEvent?->participation_price) }}" placeholder="10000">
    </div>

    <div class="package-pricing-fields" data-package-pricing-fields>
        <div class="package-pricing-intro"><div><strong>Event packages</strong><small>Capacity and per-registration limits are optional. The overall event capacity still applies.</small></div><button class="btn secondary" type="button" data-add-package>＋ Add package</button></div>
        <div class="package-pricing-rows" data-package-rows>
            @foreach($packageRows as $index => $package)
                @php($packageActive = filter_var($package['is_active'] ?? true, FILTER_VALIDATE_BOOL))
                <article class="package-pricing-row" data-package-row data-index="{{ $index }}">
                    <div class="package-row-heading"><div><span class="package-number">Package {{ $loop->iteration }}</span><strong data-package-title>{{ ($package['name'] ?? '') ?: 'New package' }}</strong></div><button type="button" data-remove-package aria-label="Remove package">Remove</button></div>
                    @if(!empty($package['id']))<input type="hidden" name="packages[{{ $index }}][id]" value="{{ $package['id'] }}">@endif
                    <div class="package-row-grid">
                        <div><label for="package-{{ $index }}-name">Package name</label><input id="package-{{ $index }}-name" name="packages[{{ $index }}][name]" value="{{ $package['name'] ?? '' }}" maxlength="100" placeholder="VIP" data-package-name></div>
                        <div><label for="package-{{ $index }}-price">Price per person</label><input id="package-{{ $index }}-price" type="number" name="packages[{{ $index }}][price]" value="{{ $package['price'] ?? '' }}" min="0.01" step="0.01" placeholder="25000" data-package-price></div>
                        <div class="wide"><label for="package-{{ $index }}-description">Short description <span>optional</span></label><textarea id="package-{{ $index }}-description" name="packages[{{ $index }}][description]" maxlength="500" placeholder="Reserved seating and priority entry">{{ $package['description'] ?? '' }}</textarea></div>
                        <div><label for="package-{{ $index }}-capacity">Package capacity <span>optional</span></label><input id="package-{{ $index }}-capacity" type="number" name="packages[{{ $index }}][capacity]" value="{{ $package['capacity'] ?? '' }}" min="1" max="100000" placeholder="No package limit"></div>
                        <div><label for="package-{{ $index }}-maximum">Maximum per registration <span>optional</span></label><input id="package-{{ $index }}-maximum" type="number" name="packages[{{ $index }}][max_per_registration]" value="{{ $package['max_per_registration'] ?? '' }}" min="1" max="10" placeholder="Event default"></div>
                    </div>
                    <label class="package-active-toggle"><input type="hidden" name="packages[{{ $index }}][is_active]" value="0"><input type="checkbox" name="packages[{{ $index }}][is_active]" value="1" @checked($packageActive) data-package-active><span><b>Available to participants</b><small>Turn this off to archive the package without changing existing registrations.</small></span></label>
                </article>
            @endforeach
        </div>
        <p class="package-pricing-note">Existing registrations keep the package name and price originally confirmed, even if you edit or archive a package later.</p>
    </div>

    <template data-package-template>
        <article class="package-pricing-row" data-package-row data-index="__INDEX__">
            <div class="package-row-heading"><div><span class="package-number">Package</span><strong data-package-title>New package</strong></div><button type="button" data-remove-package aria-label="Remove package">Remove</button></div>
            <div class="package-row-grid">
                <div><label for="package-__INDEX__-name">Package name</label><input id="package-__INDEX__-name" name="packages[__INDEX__][name]" maxlength="100" placeholder="VIP" data-package-name></div>
                <div><label for="package-__INDEX__-price">Price per person</label><input id="package-__INDEX__-price" type="number" name="packages[__INDEX__][price]" min="0.01" step="0.01" placeholder="25000" data-package-price></div>
                <div class="wide"><label for="package-__INDEX__-description">Short description <span>optional</span></label><textarea id="package-__INDEX__-description" name="packages[__INDEX__][description]" maxlength="500" placeholder="Reserved seating and priority entry"></textarea></div>
                <div><label for="package-__INDEX__-capacity">Package capacity <span>optional</span></label><input id="package-__INDEX__-capacity" type="number" name="packages[__INDEX__][capacity]" min="1" max="100000" placeholder="No package limit"></div>
                <div><label for="package-__INDEX__-maximum">Maximum per registration <span>optional</span></label><input id="package-__INDEX__-maximum" type="number" name="packages[__INDEX__][max_per_registration]" min="1" max="10" placeholder="Event default"></div>
            </div>
            <label class="package-active-toggle"><input type="hidden" name="packages[__INDEX__][is_active]" value="0"><input type="checkbox" name="packages[__INDEX__][is_active]" value="1" checked data-package-active><span><b>Available to participants</b><small>Turn this off to archive the package without changing existing registrations.</small></span></label>
        </article>
    </template>
</div>

<script>
(function(){
    document.querySelectorAll('[data-package-pricing]').forEach(function(builder){
        const modes=builder.querySelectorAll('input[name="pricing_mode"]'),single=builder.querySelector('[data-single-pricing]'),singlePrice=builder.querySelector('#participation-price'),currency=builder.querySelector('#participation-currency'),packages=builder.querySelector('[data-package-pricing-fields]'),rows=builder.querySelector('[data-package-rows]'),template=builder.querySelector('[data-package-template]'),add=builder.querySelector('[data-add-package]');
        let nextIndex=Array.from(rows.querySelectorAll('[data-package-row]')).reduce(function(max,row){return Math.max(max,Number(row.dataset.index)||0);},-1)+1;
        function selectedMode(){const selected=builder.querySelector('input[name="pricing_mode"]:checked');return selected?selected.value:'single';}
        function prepareRow(row){
            const name=row.querySelector('[data-package-name]'),active=row.querySelector('[data-package-active]'),remove=row.querySelector('[data-remove-package]'),title=row.querySelector('[data-package-title]');
            function updateTitle(){title.textContent=name.value.trim()||'New package';}
            name.addEventListener('input',updateTitle);updateTitle();
            active.addEventListener('change',sync);
            remove.addEventListener('click',function(){row.remove();if(!rows.querySelector('[data-package-row]'))addRow();renumber();sync();});
        }
        function renumber(){rows.querySelectorAll('[data-package-row]').forEach(function(row,index){const number=row.querySelector('.package-number');if(number)number.textContent='Package '+(index+1);});add.disabled=rows.querySelectorAll('[data-package-row]').length>=30;}
        function addRow(){if(rows.querySelectorAll('[data-package-row]').length>=30)return;const holder=document.createElement('div');holder.innerHTML=template.innerHTML.replaceAll('__INDEX__',String(nextIndex++));const row=holder.firstElementChild;rows.appendChild(row);prepareRow(row);renumber();sync();row.querySelector('[data-package-name]').focus();}
        function sync(){
            const paid=builder.dataset.paid==='1',mode=selectedMode(),singleMode=mode==='single';
            single.hidden=!singleMode;packages.hidden=singleMode;singlePrice.disabled=!paid||!singleMode;singlePrice.required=paid&&singleMode;currency.disabled=!paid;currency.required=paid;
            rows.querySelectorAll('[data-package-row]').forEach(function(row){const active=row.querySelector('[data-package-active]');row.querySelectorAll('input,textarea,select').forEach(function(field){field.disabled=!paid||singleMode;});const required=paid&&!singleMode&&active.checked;row.querySelector('[data-package-name]').required=required;row.querySelector('[data-package-price]').required=required;row.classList.toggle('is-inactive',!active.checked);});
        }
        modes.forEach(function(mode){mode.addEventListener('change',sync);});add.addEventListener('click',addRow);rows.querySelectorAll('[data-package-row]').forEach(prepareRow);renumber();builder._syncPackagePricing=sync;sync();
    });
    window.syncParticipationPricing=function(paid){document.querySelectorAll('[data-package-pricing]').forEach(function(builder){builder.dataset.paid=paid?'1':'0';if(builder._syncPackagePricing)builder._syncPackagePricing();});};
}());
</script>
