@extends('layout')
@section('title','Okesheni · Invitations, RSVPs, payments and event access')
@section('content')
@php
    $professionalEventUrl = auth()->check() ? '/events/create?event_type=event' : '/register?event_type=event';
    $okesheniCodeUrl = auth()->check() ? '/events/create?event_type=hangout' : '/register?event_type=hangout';
@endphp
<main class="corporate-landing">
    <section class="join-event-band is-top" aria-labelledby="join-event-title">
        <div class="wrap join-event-inner">
            <div><span class="join-event-mark" aria-hidden="true">#</span><span><small>{{ __('ui.landing.join_question') }}</small><strong id="join-event-title">{{ __('ui.landing.join_title') }}</strong></span></div>
            <form class="event-code-search is-standalone" method="get" action="{{ route('events.public.find') }}">
                <label class="sr-only" for="public-event-code">{{ __('ui.landing.code_label') }}</label>
                <input id="public-event-code" name="code" value="{{ old('code') }}" minlength="5" maxlength="5" pattern="[A-Za-z0-9]{5}" autocomplete="off" autocapitalize="characters" placeholder="A7K2P" aria-describedby="event-code-help" required>
                <button type="submit">{{ __('ui.landing.find_event') }} <span aria-hidden="true">→</span></button>
                <small id="event-code-help">{{ __('ui.landing.code_help') }}</small>
            </form>
        </div>
    </section>

    <section class="corp-hero">
        <div class="wrap corp-hero-grid">
            <div class="corp-hero-copy reveal">
                <span class="eyebrow">{{ __('ui.landing.eyebrow') }}</span>
                @php($heroTitle = explode('|', __('ui.landing.title'), 2))
                <h1>{{ $heroTitle[0] }}<br>{{ $heroTitle[1] ?? '' }}</h1>
                <p class="hero-lead">{{ __('ui.landing.lead') }}</p>
                <div class="actions hero-path-actions">
                    <a class="btn" href="{{ $professionalEventUrl }}">{{ __('ui.landing.professional_cta') }} <span aria-hidden="true">→</span></a>
                    <a class="btn secondary" href="{{ $okesheniCodeUrl }}">{{ __('ui.landing.code_cta') }}</a>
                </div>
                <div class="hero-proof"><span>{{ __('ui.landing.proof_brand') }}</span><span>{{ __('ui.landing.proof_rsvp') }}</span><span>{{ __('ui.landing.proof_access') }}</span></div>
            </div>

            <div class="event-slider reveal" data-slider aria-label="Events organised with Okesheni">
                <div class="event-slides">
                    <figure class="event-slide is-active"><img src="/assets/images/conference.png" alt="A speaker addressing guests at a professional conference"><figcaption><span>01</span><strong>Professional events, confidently coordinated</strong></figcaption></figure>
                    <figure class="event-slide"><img src="/assets/gala-hero.png" alt="Friends enjoying a beautifully arranged celebration dinner"><figcaption><span>02</span><strong>Personal occasions, effortlessly shared</strong></figcaption></figure>
                    <figure class="event-slide"><img src="/assets/images/networking.png" alt="Guests connecting at an organised gathering"><figcaption><span>03</span><strong>Every guest journey, clearly connected</strong></figcaption></figure>
                </div>
                <div class="slider-controls"><button type="button" data-prev aria-label="Previous image">←</button><div class="slider-dots"><button type="button" class="is-active" data-slide="0" aria-label="Show professional event image"></button><button type="button" data-slide="1" aria-label="Show personal occasion image"></button><button type="button" data-slide="2" aria-label="Show guest experience image"></button></div><button type="button" data-next aria-label="Next image">→</button></div>
                <div class="slider-stat"><b>One platform</b><span>For every kind of host</span></div>
            </div>
        </div>
    </section>

    <section class="wrap occasion-paths" aria-labelledby="occasion-paths-title">
        <header class="section-intro centred reveal"><span class="eyebrow">{{ __('ui.landing.choose') }}</span><h2 id="occasion-paths-title">{{ __('ui.landing.two_ways') }}</h2><p>{{ __('ui.landing.two_ways_copy') }}</p></header>
        <div class="occasion-path-grid">
            <article id="professional-events" class="occasion-path occasion-path-professional reveal">
                <div class="occasion-path-head"><span class="occasion-path-icon" aria-hidden="true">◇</span><span class="occasion-path-label">{{ __('ui.landing.teams') }}</span></div>
                <h2>{{ __('ui.landing.professional_events') }}</h2>
                <p>{{ __('ui.landing.professional_copy') }}</p>
                <ul><li>Organisation branding and invitation design</li><li>Guest lists, approvals and attendance reports</li><li>Participation payments and settlements</li><li>QR passes and controlled entry</li></ul>
                <div class="occasion-examples"><span>Conferences</span><span>Launches</span><span>Galas</span><span>Forums</span></div>
                <a class="btn" href="{{ $professionalEventUrl }}">{{ __('ui.landing.professional_cta') }} <span aria-hidden="true">→</span></a>
            </article>
            <article id="okesheni-code" class="occasion-path occasion-path-code reveal">
                <div class="occasion-path-head"><span class="occasion-path-icon" aria-hidden="true">✦</span><span class="occasion-path-label">{{ __('ui.landing.personal') }}</span></div>
                <h2>Okesheni Code</h2>
                <p>{{ __('ui.landing.code_copy') }}</p>
                <ul><li>Create and share in a few clear steps</li><li>Invite with a private link or event code</li><li>Collect RSVPs and participation payments</li><li>Send confirmed guests a QR invitation</li></ul>
                <div class="okesheni-code-preview" aria-label="Example Okesheni event code"><small>{{ __('ui.landing.your_code') }}</small><strong>K7P2A</strong><span>{{ __('ui.landing.ready_share') }}</span></div>
                <a class="btn" href="{{ $okesheniCodeUrl }}">{{ __('ui.landing.code_cta') }} <span aria-hidden="true">→</span></a>
            </article>
        </div>
    </section>

    <section class="corp-strip"><div class="wrap"><span>MADE FOR</span><strong>Conferences</strong><strong>Private launches</strong><strong>Birthdays</strong><strong>Dinners</strong><strong>Reunions</strong><strong>Celebrations</strong></div></section>

    <section class="wrap platform-infographic reveal" aria-label="Okesheni platform overview"><div><strong>3</strong><span>Integrated delivery channels</span></div><i></i><div><strong>1</strong><span>Clear organiser dashboard</span></div><i></i><div><strong>5</strong><span>Characters in every event code</span></div><i></i><div><strong>QR</strong><span>Guest access when confirmed</span></div></section>

    <section id="product" class="wrap corp-section">
        <div class="section-intro reveal"><span class="eyebrow">{{ __('ui.landing.journey_eyebrow') }}</span><h2>{{ __('ui.landing.journey_title') }}</h2><p>{{ __('ui.landing.journey_copy') }}</p></div>
        <div class="corp-feature-grid shared-feature-grid">
            <article class="corp-feature featured"><span class="feature-number">01</span><h3>Invite in your own style</h3><p>Add an organisation logo or occasion artwork, choose your colours and share a personal invitation through email, SMS or WhatsApp.</p><div class="palette-sample"><i></i><i></i><i></i><span>Professional or personal—always recognisably yours.</span></div></article>
            <article class="corp-feature"><span class="feature-number">02</span><h3>Ask only what matters</h3><p>Configure RSVP questions, guest limits and confirmation rules to match a formal event or a relaxed gathering.</p></article>
            <article class="corp-feature"><span class="feature-number">03</span><h3>Collect participation payments</h3><p>Let guests pay through Bluetick Pay or provide manual payment instructions, with status tracking for the organiser.</p></article>
            <article class="corp-feature"><span class="feature-number">04</span><h3>Welcome guests with confidence</h3><p>Issue QR invitations after confirmation and scan them at the entrance while respecting each invitation’s guest allowance.</p></article>
        </div>
    </section>

    <section id="how" class="corp-process"><div class="wrap"><div class="section-intro light reveal"><span class="eyebrow">{{ __('ui.landing.journey_eyebrow') }}</span><h2>{{ __('ui.landing.journey_title') }}</h2></div><div class="process-grid"><article><span>01</span><h3>{{ __('ui.landing.step_create') }}</h3><p>Choose Professional Event or Okesheni Code, then add the details your guests need.</p></article><article><span>02</span><h3>{{ __('ui.landing.step_invite') }}</h3><p>Add your guest list or share the registration link and five-character event code.</p></article><article><span>03</span><h3>{{ __('ui.landing.step_confirm') }}</h3><p>Receive RSVPs, collect required payments and issue confirmed QR invitations.</p></article><article><span>04</span><h3>{{ __('ui.landing.step_welcome') }}</h3><p>Track responses, scan guests on arrival and keep an accurate attendance record.</p></article></div></div></section>

    <section class="wrap visual-story reveal"><div class="visual-story-image"><img src="/assets/gala-hero.png" alt="Friends sharing a special dinner by the water"><span>Made for meaningful moments</span></div><div><span class="eyebrow">Okesheni Code</span><h2>Send the details once. Know who’s coming.</h2><p>Create a thoughtful invitation for a birthday, dinner, reunion or weekend gathering without turning it into a complicated project.</p><div class="journey-flow"><span>Create</span><i>→</i><span>Share</span><i>→</i><span>Confirm</span><i>→</i><span>Gather</span></div><a class="text-link" href="{{ $okesheniCodeUrl }}">Create your Okesheni Code <span aria-hidden="true">→</span></a></div></section>

    <section class="wrap corp-section channel-section"><div class="reveal"><span class="eyebrow">Comfortable for every guest</span><h2>A clear invitation. A simple response.</h2><p>Guests receive the essential details, attendance choices, payment requirements and QR access in a mobile-friendly experience.</p><ul class="check-list"><li>Unique, private response links</li><li>Configurable RSVP questions</li><li>Controlled guest and seat limits</li><li>Immediate organiser visibility</li></ul></div><div class="channel-card reveal"><span class="eyebrow">Meet guests where they are</span><div><b>EMAIL</b><p>Branded invitations with event details and a personal response link.</p></div><div><b>SMS</b><p>Concise messages that make it easy to open and respond from any phone.</p></div><div><b>WHATSAPP</b><p>Direct invitation delivery for professional and personal guest lists.</p></div></div></section>

    <section id="clients" class="client-section"><div class="wrap"><div class="section-intro reveal"><span class="eyebrow">Hosts and organisers</span><h2>Built for people who care about the guest experience.</h2><p>Whether you are coordinating on behalf of an organisation or bringing your favourite people together, Okesheni keeps the experience considered and clear.</p></div><div class="audience-grid"><div><span>01</span><strong>Corporate teams</strong><p>Leadership forums, launches, annual events and stakeholder gatherings.</p></div><div><span>02</span><strong>Event professionals</strong><p>Agencies and planners managing invitations and attendance for clients.</p></div><div><span>03</span><strong>Institutions &amp; venues</strong><p>Ceremonies, programmes and guest-ready experiences that need reliable coordination.</p></div><div><span>04</span><strong>Friends &amp; families</strong><p>Birthdays, dinners, reunions and private celebrations without the group-chat confusion.</p></div></div></div></section>

    <section id="partners" class="wrap partner-section"><div class="partner-copy reveal"><span class="eyebrow">Partners</span><h2>Better occasions are built together.</h2><p>We work alongside event agencies, venues, hospitality providers, communications teams and technology partners to create a connected guest journey.</p><a class="btn secondary" href="mailto:{{ config('mail.from.address') }}">Partner with Okesheni <span aria-hidden="true">→</span></a></div><div class="partner-types reveal"><span>Event agencies</span><span>Venues</span><span>Hospitality</span><span>Communications</span><span>Technology</span><span>Production teams</span></div></section>

    <section class="image-break reveal"><img src="/assets/images/award.png" alt="An award presented during a professionally organised gala"><div><img src="/assets/logo/logo_vertical.png" alt="Okesheni"><span class="eyebrow">Every occasion, beautifully organised</span><h2>Make bringing people together feel effortless.</h2></div></section>

    <section class="corp-cta"><div class="reveal"><span class="eyebrow">{{ __('ui.landing.start') }}</span><h2>{{ __('ui.landing.closing_title') }}</h2><p>{{ __('ui.landing.closing_copy') }}</p><div class="cta-actions"><a class="btn" href="{{ $professionalEventUrl }}">{{ __('ui.landing.professional_cta') }}</a><a class="btn secondary" href="{{ $okesheniCodeUrl }}">{{ __('ui.landing.code_cta') }}</a></div></div></section>
</main>
<script>
    (function () {
        const slider = document.querySelector('[data-slider]');
        if (slider) {
            const slides = [...slider.querySelectorAll('.event-slide')], dots = [...slider.querySelectorAll('[data-slide]')]; let current = 0;
            const show = function (index) { current = (index + slides.length) % slides.length; slides.forEach((slide, i) => slide.classList.toggle('is-active', i === current)); dots.forEach((dot, i) => dot.classList.toggle('is-active', i === current)); };
            slider.querySelector('[data-prev]').addEventListener('click', function () { show(current - 1); }); slider.querySelector('[data-next]').addEventListener('click', function () { show(current + 1); }); dots.forEach(function (dot) { dot.addEventListener('click', function () { show(Number(dot.dataset.slide)); }); });
            if (!window.matchMedia('(prefers-reduced-motion: reduce)').matches) window.setInterval(function () { show(current + 1); }, 5500);
        }
        if ('IntersectionObserver' in window) { const observer = new IntersectionObserver(function (entries) { entries.forEach(function (entry) { if (entry.isIntersecting) entry.target.classList.add('is-visible'); }); }, { threshold: .12 }); document.querySelectorAll('.reveal,.corp-feature,.process-grid article,.audience-grid>div').forEach(function (element) { element.classList.add('reveal'); observer.observe(element); }); } else document.querySelectorAll('.reveal').forEach(function (element) { element.classList.add('is-visible'); });
        const code=document.getElementById('public-event-code');if(code)code.addEventListener('input',function(){code.value=code.value.toUpperCase().replace(/[^A-Z0-9]/g,'').slice(0,5);});
    }());
</script>
@endsection
