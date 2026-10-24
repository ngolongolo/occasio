<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="referrer" content="strict-origin-when-cross-origin"><meta name="theme-color" content="#56283f"><meta name="application-name" content="Okesheni"><meta name="apple-mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-status-bar-style" content="default"><meta name="apple-mobile-web-app-title" content="Okesheni"><meta name="mobile-web-app-capable" content="yes">
    <title>@yield('title','Okesheni · Every invitation, beautifully connected')</title>
    <link rel="icon" type="image/png" href="/assets/logo/favicon.png"><link rel="apple-touch-icon" href="/assets/logo/favicon.png"><link rel="manifest" href="/manifest.webmanifest"><link rel="stylesheet" href="/assets/style.css"><link rel="stylesheet" href="/assets/guests.css?v={{ filemtime(public_path('assets/guests.css')) }}"><link rel="stylesheet" href="/assets/cobrand.css?v={{ filemtime(public_path('assets/cobrand.css')) }}"><link rel="stylesheet" href="/assets/landing.css?v={{ filemtime(public_path('assets/landing.css')) }}"><link rel="stylesheet" href="/assets/auth.css?v={{ filemtime(public_path('assets/auth.css')) }}"><link rel="stylesheet" href="/assets/pwa.css?v={{ filemtime(public_path('assets/pwa.css')) }}"><link rel="stylesheet" href="/assets/venue-picker.css?v={{ filemtime(public_path('assets/venue-picker.css')) }}"><script defer src="/assets/venue-picker.js?v={{ filemtime(public_path('assets/venue-picker.js')) }}"></script>
</head>
<body>
    <nav class="site-nav"><a href="/" aria-label="Okesheni home"><img src="/assets/logo/logo_main.png" alt="Okesheni"></a><button class="site-nav-toggle" id="site-nav-toggle" type="button" aria-expanded="false" aria-controls="site-nav-links"><span>{{ __('ui.nav.menu') }}</span><i aria-hidden="true"></i></button><div class="links" id="site-nav-links">@auth @if(auth()->user()->isSuperAdmin())<a class="admin-nav-link" href="{{ route('admin.index') }}">{{ __('ui.nav.system_admin') }}</a>@else<a href="/dashboard">{{ __('ui.nav.my_events') }}</a><a href="{{ route('organiser.verification.show') }}">{{ __('ui.nav.verification') }}</a>@endif<form method="post" action="/logout">@csrf<button class="btn small">{{ __('ui.nav.sign_out') }}</button></form>@else<a href="/#professional-events">{{ __('ui.nav.professional_events') }}</a><a href="/#okesheni-code">Okesheni Code</a><a href="/#how">{{ __('ui.nav.how_it_works') }}</a><a href="/login">{{ __('ui.nav.log_in') }}</a><a class="btn small" href="/register">{{ __('ui.nav.get_started') }} ↗</a>@endauth @include('partials.language-switcher')<button class="pwa-install-button" id="pwa-install" type="button" hidden>{{ __('ui.nav.install_app') }}</button></div></nav>
    <div class="network-status" id="network-status" hidden role="status" aria-live="polite">{{ __('ui.common.offline') }}</div>

    @if(session('success') || $errors->any() || session('import_errors'))
        <div id="response-toast" class="response-toast {{ $errors->any() || session('import_errors') ? 'is-error' : 'is-success' }}" role="status" aria-live="polite">
            <div>@if(session('success'))<strong>{{ session('success') }}</strong>@endif @foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach @foreach(session('import_errors',[]) as $error)<div>{{ $error }}</div>@endforeach</div>
            <button type="button" aria-label="Close message" onclick="this.parentElement.remove()">×</button>
        </div>
    @endif

    @yield('content')
    <footer><img src="/assets/logo/logo_large.png" alt="Okesheni"><span>© {{ date('Y') }} Okesheni</span><span>{{ __('ui.common.footer') }}</span></footer>
    <div id="submit-loader" class="submit-loader" hidden role="status" aria-live="assertive" aria-label="{{ __('ui.common.processing') }}">
        <div class="submit-loader-brand"><span class="loader-orbit" aria-hidden="true"></span><img src="/assets/logo/favicon.png" alt=""></div>
        <strong>{{ __('ui.common.processing') }}…</strong><small>{{ __('ui.common.wait') }}</small>
    </div>
    <script>
        document.addEventListener('submit', function (event) {
            if (event.defaultPrevented || event.target.method.toLowerCase() !== 'post' || event.target.hasAttribute('data-no-loader')) return;
            document.getElementById('submit-loader').hidden = false;
            event.target.querySelectorAll('button[type="submit"], button:not([type])').forEach(function (button) { button.disabled = true; });
        });
        const selectAll = document.getElementById('select-all-guests');
        if (selectAll) selectAll.addEventListener('change', function () {
            document.querySelectorAll('.guest-selector').forEach(function (box) { box.checked = selectAll.checked; });
        });
        const toast = document.getElementById('response-toast');
        if (toast) window.setTimeout(function () { toast.remove(); }, 7000);
        (function () {
            const toggle = document.getElementById('site-nav-toggle'), links = document.getElementById('site-nav-links');
            if (!toggle || !links) return;
            const close = function () { links.classList.remove('is-open'); toggle.setAttribute('aria-expanded', 'false'); };
            toggle.addEventListener('click', function () { const opening = !links.classList.contains('is-open'); links.classList.toggle('is-open', opening); toggle.setAttribute('aria-expanded', opening ? 'true' : 'false'); });
            document.addEventListener('keydown', function (event) { if (event.key === 'Escape') { close(); toggle.focus(); } });
            document.addEventListener('click', function (event) { if (!event.target.closest('.site-nav')) close(); });
            links.querySelectorAll('a').forEach(function (link) { link.addEventListener('click', close); });
        }());
        @if($errors->any())
        (function () {
            const validationErrors = @json($errors->messages());
            let firstInvalid = null;
            const fieldName = function (key) { const parts = key.split('.'); return parts.shift() + parts.map(function (part) { return '[' + part + ']'; }).join(''); };
            Object.entries(validationErrors).forEach(function (entry) {
                const key = entry[0], messages = entry[1], name = fieldName(key);
                let field = Array.from(document.querySelectorAll('[name]')).find(function (candidate) { return candidate.name === name; });
                if (!field && key.indexOf('.') === -1) field = Array.from(document.querySelectorAll('[name]')).find(function (candidate) { return candidate.name.indexOf(key + '[') === 0; });
                if (!field) return;
                field.setAttribute('aria-invalid', 'true');
                const message = document.createElement('small');
                message.className = 'field-error-message';
                message.setAttribute('role', 'alert');
                message.textContent = messages[0];
                const group = field.closest('fieldset,[data-package-row],.auth-field,.create-field') || field.parentElement;
                group.appendChild(message);
                firstInvalid ||= field;
            });
            if (firstInvalid) window.setTimeout(function () { firstInvalid.focus({ preventScroll: true }); firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' }); }, 100);
        }());
        @endif
        function togglePasswordVisibility(toggle) {
            const input = document.getElementById(toggle.getAttribute('aria-controls'));
            if (!input) return;
            const show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            toggle.setAttribute('aria-pressed', show ? 'true' : 'false');
            toggle.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
            toggle.title = show ? 'Hide password' : 'Show password';
            input.focus();
        }
        (function () {
            const installButton = document.getElementById('pwa-install'), networkStatus = document.getElementById('network-status'); let installPrompt = null;
            function syncConnection() { networkStatus.hidden = navigator.onLine; document.documentElement.classList.toggle('is-offline', !navigator.onLine); }
            window.addEventListener('online', syncConnection); window.addEventListener('offline', syncConnection); syncConnection();
            window.addEventListener('beforeinstallprompt', function (event) { event.preventDefault(); installPrompt = event; installButton.hidden = false; });
            installButton.addEventListener('click', async function () { if (!installPrompt) return; installPrompt.prompt(); await installPrompt.userChoice; installPrompt = null; installButton.hidden = true; });
            window.addEventListener('appinstalled', function () { installPrompt = null; installButton.hidden = true; });
            if ('serviceWorker' in navigator) {
                window.addEventListener('load', function () { navigator.serviceWorker.register('/service-worker.js?v=3', { updateViaCache: 'none' }).then(function (registration) { registration.update(); }).catch(function () {}); });
                let reloading = false; navigator.serviceWorker.addEventListener('controllerchange', function () { if (reloading) return; reloading = true; window.location.reload(); });
            }
        }());
    </script>
</body>
</html>
