@extends('layout')
@section('title', 'Invitation design · Okesheni')
@section('content')
<main class="page">
    <div class="page-head"><div><span class="eyebrow">{{ $event->title }}</span><h1>Invitation design</h1><p>Tailor email branding, attach your designed card, and write the SMS sent to guests.</p></div><a class="btn secondary" href="{{ route('events.show', $event) }}">← Event</a></div>
    @include('partials.alerts')
    <form class="panel design-form" method="post" action="{{ route('events.design.update', $event) }}" enctype="multipart/form-data">
        @csrf @method('put')
        <section><h3>Guest response options</h3><p class="muted">Choose what invited guests can do from their private invitation link. At least one option is required.</p><input type="hidden" name="response_options_present" value="1"><label class="check-row"><input type="checkbox" name="participation_enabled" value="1" @checked(old('participation_enabled', $event->participation_enabled))> Allow guests to confirm participation</label><label class="check-row"><input type="checkbox" name="pledge_enabled" value="1" @checked(old('pledge_enabled', $event->pledge_enabled))> Allow guests to make a pledge</label><div class="form-grid"><div><label for="pledge_currency">Pledge currency</label><input id="pledge_currency" name="pledge_currency" value="{{ old('pledge_currency', $event->pledge_currency ?: 'TZS') }}" maxlength="3" required></div></div><label for="pledge_message">Pledge message</label><textarea id="pledge_message" name="pledge_message" maxlength="2000" placeholder="Explain what the pledge will support.">{{ old('pledge_message', $event->pledge_message) }}</textarea><label for="payment_details">Payment details</label><textarea id="payment_details" name="payment_details" maxlength="5000" placeholder="Bank or mobile-money account name, number, and payment instructions.">{{ old('payment_details', $event->payment_details) }}</textarea><p class="muted">Payment details are shown only when pledges are enabled. A pledge is not considered paid until an administrator confirms it.</p></section>
        <section><h3>Email style</h3><div class="palette-grid"><label>Accent and button<input id="primary_color" type="color" name="primary_color" value="{{ old('primary_color', $event->primary_color ?: '#56283f') }}"></label><label>Background<input id="background_color" type="color" name="background_color" value="{{ old('background_color', $event->background_color ?: '#fcf9f4') }}"></label><label>Text<input id="text_color" type="color" name="text_color" value="{{ old('text_color', $event->text_color ?: '#56283f') }}"></label></div><div id="invitation-palette-preview" class="palette-preview"><strong>{{ $event->title }}</strong><p>Dear Guest, you’re invited to celebrate with us.</p><span>Confirm your attendance</span></div><label for="logo">Logo · PNG or JPG, up to 2 MB</label><input id="logo" type="file" name="logo" accept=".png,.jpg,.jpeg,image/png,image/jpeg">@if($event->logo_path)<label class="check-row"><input type="checkbox" name="remove_logo" value="1"> Remove current logo</label>@endif</section>
        <section><h3>Designed invitation card</h3><p class="muted">Upload a PDF or PNG up to 10 MB. The card will appear inside the email and RSVP form, and the original file will remain attached.</p><input type="file" name="invitation_card" accept=".pdf,.png,application/pdf,image/png">@if($event->invitation_card_path)<p>Current: <a href="{{ route('events.design.card', $event) }}">{{ $event->invitation_card_name }}</a></p><label class="check-row"><input type="checkbox" name="remove_invitation_card" value="1"> Remove current card</label>@endif</section>
        <section><h3>SMS template</h3><p class="muted">Available placeholders: <code>{guest_name}</code>, <code>{event_title}</code>, <code>{host}</code>, <code>{event_date}</code>, <code>{venue}</code>, <code>{rsvp_url}</code>.</p><textarea name="sms_template" maxlength="1000" required>{{ old('sms_template', $event->sms_template ?: $defaultSms) }}</textarea></section>
        <div class="form-actions"><a class="btn secondary" href="{{ route('events.show', $event) }}">Cancel</a><button type="submit">Save invitation design</button></div>
    </form>
</main>
<script>
    const palettePreview = document.getElementById('invitation-palette-preview');
    function updatePalettePreview() {
        palettePreview.style.setProperty('--preview-accent', document.getElementById('primary_color').value);
        palettePreview.style.setProperty('--preview-background', document.getElementById('background_color').value);
        palettePreview.style.setProperty('--preview-text', document.getElementById('text_color').value);
    }
    ['primary_color', 'background_color', 'text_color'].forEach(function (id) { document.getElementById(id).addEventListener('input', updatePalettePreview); });
    updatePalettePreview();
</script>
@endsection
