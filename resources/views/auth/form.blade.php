@extends('layout')
@section('title', ($register ? __('ui.auth.create_account') : __('ui.auth.welcome_back')).' · Okesheni')
@section('content')
@php($requestedEventType = in_array(request('event_type'), ['event','hangout'], true) ? request('event_type') : old('event_type'))
<main class="auth-page">
    <section class="auth-story" aria-label="Okesheni introduction">
        <div class="auth-story-glow" aria-hidden="true"></div>
        <a class="auth-story-brand" href="/" aria-label="Okesheni home"><img src="/assets/logo/logo_main.png" alt="Okesheni"></a>
        <div class="auth-story-copy">
            <span class="auth-kicker">{{ __('ui.auth.story_kicker') }}</span>
            <h1>{{ $register ? __('ui.auth.register_story_title') : __('ui.auth.login_story_title') }}</h1>
            <p>{{ $register ? __('ui.auth.register_story_copy') : __('ui.auth.login_story_copy') }}</p>
            <div class="auth-story-points">
                @if($register)
                    <div><span>01</span><p><strong>{{ __('ui.auth.create_ease') }}</strong><small>{{ __('ui.auth.create_ease_copy') }}</small></p></div>
                    <div><span>02</span><p><strong>{{ __('ui.auth.invite_beautifully') }}</strong><small>{{ __('ui.auth.invite_beautifully_copy') }}</small></p></div>
                    <div><span>03</span><p><strong>{{ __('ui.auth.stay_informed') }}</strong><small>{{ __('ui.auth.stay_informed_copy') }}</small></p></div>
                @else
                    <blockquote>“{{ __('ui.auth.quote') }}”</blockquote>
                    <div class="auth-mini-event"><span class="auth-mini-date"><b>{{ now()->format('d') }}</b>{{ now()->format('M') }}</span><p><small>{{ __('ui.auth.workspace') }}</small><strong>{{ __('ui.auth.workspace_copy') }}</strong></p><i aria-hidden="true">→</i></div>
                @endif
            </div>
        </div>
        <small class="auth-story-foot">{{ __('ui.auth.story_foot') }}</small>
    </section>

    <section class="auth-access">
        <div class="auth-access-inner">
            <header class="auth-heading">
                <span class="eyebrow">{{ $register ? __('ui.auth.start_planning') : __('ui.auth.welcome_back') }}</span>
                <h2>{{ $register ? __('ui.auth.create_account') : __('ui.auth.sign_in') }}</h2>
                <p>{{ __('ui.auth.contact_copy') }}</p>
            </header>

            @include('partials.alerts')

            <form class="auth-form" method="post" action="{{ $register ? '/register' : '/login' }}">
                @csrf
                @if(in_array($requestedEventType,['event','hangout'],true))<input type="hidden" name="event_type" value="{{ $requestedEventType }}">@endif
                @if($register)
                    <div class="auth-field"><label for="auth-name">{{ __('ui.auth.your_name') }}</label><div class="auth-input-wrap"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="3.5"/><path d="M5 20c.7-4.3 3-6.5 7-6.5s6.3 2.2 7 6.5"/></svg><input id="auth-name" name="name" value="{{ old('name') }}" required maxlength="120" autocomplete="name" placeholder="{{ __('ui.auth.name_placeholder') }}"></div></div>
                @endif
                <div class="auth-field">
                    <label for="auth-identifier">{{ __('ui.common.email_or_mobile') }}</label>
                    <div class="auth-input-wrap">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="3.5"/><path d="M5 20c.7-4.3 3-6.5 7-6.5s6.3 2.2 7 6.5"/></svg>
                        <input id="auth-identifier" type="text" name="identifier" value="{{ old('identifier', old('email', old('phone'))) }}" required maxlength="180" autocomplete="username" autocapitalize="none" spellcheck="false" placeholder="{{ __('ui.auth.identifier_placeholder') }}">
                    </div>
                    @if($register)<small class="auth-field-hint">{{ __('ui.auth.identifier_hint') }}</small>@endif
                </div>

                <div class="auth-field">
                    @include('partials.password-input', ['id' => 'password', 'name' => 'password', 'label' => $register ? __('ui.auth.create_password') : __('ui.auth.password'), 'autocomplete' => $register ? 'new-password' : 'current-password', 'minlength' => $register ? 8 : null])
                    @if($register)<div class="password-guidance"><span><i></i></span><small>{{ __('ui.auth.password_hint') }}</small></div>@endif
                </div>
                @if($register)
                    <div class="auth-field">@include('partials.password-input', ['id' => 'password-confirmation', 'name' => 'password_confirmation', 'label' => __('ui.auth.confirm_password'), 'autocomplete' => 'new-password', 'minlength' => 8])</div>
                @else
                    <div class="auth-form-options"><label><input type="checkbox" name="remember" value="1" @checked(old('remember'))> <span>{{ __('ui.auth.remember') }}</span></label><a href="{{ route('password.request') }}">{{ __('ui.auth.forgot') }}</a></div>
                @endif

                <button class="auth-submit" type="submit"><span>{{ $register ? __('ui.auth.create_button') : __('ui.auth.signin_button') }}</span><b aria-hidden="true">→</b></button>
            </form>

            <div class="auth-switch"><span>{{ $register ? __('ui.auth.already') : __('ui.auth.new') }}</span><a href="{{ ($register ? '/login' : '/register').($requestedEventType ? '?event_type='.$requestedEventType : '') }}">{{ $register ? __('ui.auth.signin_instead') : __('ui.auth.create_link') }} <b aria-hidden="true">→</b></a></div>
            <p class="auth-privacy"><span aria-hidden="true">◇</span> {{ __('ui.auth.privacy') }}</p>
        </div>
    </section>
</main>
@if($register)
<script>
    (function(){const input=document.getElementById('password'),meter=document.querySelector('.password-guidance'),copy={hint:@json(__('ui.auth.password_hint')),keep:@json(__('ui.auth.password_keep')),good:@json(__('ui.auth.password_good')),strong:@json(__('ui.auth.password_strong'))};if(!input||!meter)return;function update(){const value=input.value;let score=0;if(value.length>=8)score++;if(/[A-Z]/.test(value)&&/[a-z]/.test(value))score++;if(/\d/.test(value)&&/[^A-Za-z0-9]/.test(value))score++;meter.dataset.score=score;meter.querySelector('small').textContent=!value?copy.hint:score<2?copy.keep:score<3?copy.good:copy.strong;}input.addEventListener('input',update);update();}());
</script>
@endif
@endsection
