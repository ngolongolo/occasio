@extends('layout')
@section('title', 'Create an event · Okesheni')
@section('content')
<main class="event-create-shell">
    <aside class="event-create-intro">
        <a class="create-back" href="/dashboard">← Back to events</a>
        <span class="eyebrow">New event</span>
        <h1>Bring the occasion to life.</h1>
        <p>Start with the essentials. You can add participants, customize the invitation, and choose response options after creating the event.</p>
        <ol class="create-steps">
            <li class="active"><span>01</span><div><strong>Event details</strong><small>Name, schedule, venue, and contact</small></div></li>
            <li><span>02</span><div><strong>Invitation design</strong><small>Branding, response options, and messaging</small></div></li>
            <li><span>03</span><div><strong>Guest delivery</strong><small>Add participants and send invitations</small></div></li>
        </ol>
        <div class="create-note"><span aria-hidden="true">✦</span><p><strong>Nothing is sent automatically.</strong><br>You stay in control of when guests receive their invitations.</p></div>
    </aside>

    <section class="event-create-card">
        <div class="create-card-header"><div><span class="eyebrow">Step 1 of 3</span><h2>Create your event</h2></div><span class="required-note"><b>*</b> Required fields</span></div>
        @include('partials.alerts')
        <form method="post" action="/events" class="event-create-form">
            @csrf
            <fieldset>
                <legend><span>01</span><div>Event identity<small>How the occasion will appear to your guests</small></div></legend>
                <div class="create-field full"><label for="event-title">Event title <b>*</b></label><input id="event-title" name="title" value="{{ old('title') }}" placeholder="Annual Leadership Forum" maxlength="180" required autofocus><small>Use a clear, recognizable name.</small></div>
                <div class="create-field full"><label for="event-host">Host or organisation <b>*</b></label><input id="event-host" name="host" value="{{ old('host') }}" placeholder="BlueTick Events" maxlength="180" required></div>
                <div class="create-field full"><div class="label-row"><label for="event-description">Invitation message <b>*</b></label><small id="description-count">0 / 5,000</small></div><textarea id="event-description" name="description" maxlength="5000" placeholder="Welcome your guests and briefly explain the occasion." required>{{ old('description') }}</textarea></div>
            </fieldset>

            <fieldset>
                <legend><span>02</span><div>Schedule<small>When guests should attend and respond</small></div></legend>
                <div class="create-field"><label for="event-start">Start date and time <b>*</b></label><input id="event-start" type="datetime-local" name="starts_at" value="{{ old('starts_at') }}" min="{{ now()->format('Y-m-d\TH:i') }}" required><small>Times are shown in East Africa Time.</small></div>
                <div class="create-field"><label for="event-deadline">Requested response date <b>*</b></label><input id="event-deadline" type="date" name="rsvp_deadline" value="{{ old('rsvp_deadline') }}" min="{{ now()->format('Y-m-d') }}" required><small>Late responses remain possible until the event starts.</small></div>
            </fieldset>

            <fieldset>
                <legend><span>03</span><div>Location and guidance<small>Practical details guests may need</small></div></legend>
                <div class="create-field full"><label for="event-venue">Venue <b>*</b></label><input id="event-venue" name="venue" value="{{ old('venue') }}" placeholder="Venue name, area, city" maxlength="255" required></div>
                <div class="create-field"><label for="event-dress-code">Dress code</label><input id="event-dress-code" name="dress_code" value="{{ old('dress_code') }}" placeholder="Business formal" maxlength="180"></div>
                <div class="create-field"><label for="event-contact">Coordinator contact</label><input id="event-contact" name="contact" value="{{ old('contact') }}" placeholder="Name, phone, or email" maxlength="100"><small>Shown to guests who need assistance.</small></div>
            </fieldset>

            <div class="create-form-actions"><a class="btn secondary" href="/dashboard">Cancel</a><button type="submit">Create event <span aria-hidden="true">→</span></button></div>
        </form>
    </section>
</main>
<script>
    (function(){
        const description=document.getElementById('event-description'),count=document.getElementById('description-count');
        function updateCount(){count.textContent=description.value.length.toLocaleString()+' / 5,000';}
        description.addEventListener('input',updateCount);updateCount();
        const start=document.getElementById('event-start'),deadline=document.getElementById('event-deadline');
        start.addEventListener('change',function(){if(!start.value)return;const date=start.value.slice(0,10);deadline.max=date;if(!deadline.value)deadline.value=date;});
        if(start.value)deadline.max=start.value.slice(0,10);
    }());
</script>
@endsection
