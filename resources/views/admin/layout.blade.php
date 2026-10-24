<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <meta name="theme-color" content="#351725">
    <meta name="application-name" content="Okesheni System Admin">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="Okesheni">
    <meta name="mobile-web-app-capable" content="yes">
    <title>@yield('title', 'System Admin · Okesheni')</title>
    <link rel="icon" type="image/png" href="/assets/logo/favicon.png">
    <link rel="apple-touch-icon" href="/assets/logo/favicon.png">
    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="stylesheet" href="/assets/style.css">
    <link rel="stylesheet" href="/assets/guests.css?v={{ filemtime(public_path('assets/guests.css')) }}">
    <link rel="stylesheet" href="/assets/cobrand.css?v={{ filemtime(public_path('assets/cobrand.css')) }}">
    <link rel="stylesheet" href="/assets/pwa.css?v={{ filemtime(public_path('assets/pwa.css')) }}">
    @stack('head')
</head>
<body class="admin-app-body">
    <a class="admin-skip-link" href="#admin-main">Skip to main content</a>

    <div class="admin-app-shell" data-admin-shell>
        <aside class="admin-sidebar" id="admin-sidebar" aria-label="System administration">
            <div class="admin-sidebar-brand">
                <a href="{{ route('admin.index') }}" aria-label="Okesheni System Admin overview">
                    <img src="/assets/logo/logo_main.png" alt="Okesheni">
                    <span>System Admin</span>
                </a>
                <button class="admin-sidebar-close" type="button" data-admin-nav-close aria-label="Close administration menu">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"/></svg>
                </button>
            </div>

            @include('admin.nav')

            <div class="admin-sidebar-footer">
                @include('partials.language-switcher')
                <a class="admin-view-site" href="/">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18Zm0 0c2.2 2.5 3.3 5.5 3.3 9S14.2 18.5 12 21M3 12h18M12 3C9.8 5.5 8.7 8.5 8.7 12S9.8 18.5 12 21"/></svg>
                    <span>{{ __('ui.admin.view_site') }}</span>
                    <span aria-hidden="true">↗</span>
                </a>
                <div class="admin-account">
                    <span class="admin-account-avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr(auth()->user()->name, 0, 1)) }}</span>
                    <span class="admin-account-copy">
                        <strong>{{ auth()->user()->name }}</strong>
                        <small>{{ auth()->user()->email ?: auth()->user()->phone }}</small>
                    </span>
                    <form method="post" action="/logout">
                        @csrf
                        <button type="submit" title="Sign out" aria-label="Sign out of Okesheni">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 5H5v14h5M14 8l4 4-4 4M8 12h10"/></svg>
                        </button>
                    </form>
                </div>
            </div>
        </aside>

        <button class="admin-sidebar-scrim" type="button" data-admin-nav-close tabindex="-1" aria-label="Close administration menu"></button>

        <div class="admin-app-main">
            <header class="admin-mobile-header">
                <button class="admin-menu-button" type="button" data-admin-nav-open aria-controls="admin-sidebar" aria-expanded="false">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
                    <span class="sr-only">Open administration menu</span>
                </button>
                <a href="{{ route('admin.index') }}">
                    <img src="/assets/logo/logo_main.png" alt="Okesheni">
                    <span>System Admin</span>
                </a>
                <span class="admin-mobile-avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr(auth()->user()->name, 0, 1)) }}</span>
            </header>

            <div class="network-status" id="network-status" hidden role="status" aria-live="polite">You’re offline. Live administration data requires a connection.</div>

            @if(session('success') || $errors->any() || session('import_errors'))
                <div id="response-toast" class="response-toast {{ $errors->any() || session('import_errors') ? 'is-error' : 'is-success' }}" role="status" aria-live="polite">
                    <div>
                        @if(session('success'))<strong>{{ session('success') }}</strong>@endif
                        @foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach
                        @foreach(session('import_errors', []) as $error)<div>{{ $error }}</div>@endforeach
                    </div>
                    <button type="button" aria-label="Close message" onclick="this.parentElement.remove()">×</button>
                </div>
            @endif

            <main class="admin-console" id="admin-main" tabindex="-1">
                @yield('admin-content')
            </main>
        </div>
    </div>

    <div id="submit-loader" class="submit-loader" hidden role="status" aria-live="assertive" aria-label="Processing request">
        <div class="submit-loader-brand"><span class="loader-orbit" aria-hidden="true"></span><img src="/assets/logo/favicon.png" alt=""></div>
        <strong>Processing…</strong><small>Please wait a moment</small>
    </div>

    <script>
        (function () {
            const shell = document.querySelector('[data-admin-shell]');
            const sidebar = document.getElementById('admin-sidebar');
            const appMain = document.querySelector('.admin-app-main');
            const openButton = document.querySelector('[data-admin-nav-open]');
            const closeButtons = document.querySelectorAll('[data-admin-nav-close]');
            const desktop = window.matchMedia('(min-width: 1025px)');
            let restoreFocus = null;

            function setNavigation(open, returnFocus) {
                if (desktop.matches) open = false;
                shell.classList.toggle('is-nav-open', open);
                document.body.classList.toggle('has-admin-nav-open', open);
                openButton.setAttribute('aria-expanded', open ? 'true' : 'false');
                sidebar.setAttribute('aria-hidden', !desktop.matches && !open ? 'true' : 'false');
                sidebar.inert = !desktop.matches && !open;
                appMain.inert = open;
                if (open) {
                    sidebar.setAttribute('role', 'dialog');
                    sidebar.setAttribute('aria-modal', 'true');
                } else {
                    sidebar.removeAttribute('role');
                    sidebar.removeAttribute('aria-modal');
                }

                if (open) {
                    restoreFocus = document.activeElement;
                    sidebar.querySelector('[data-admin-nav-close]').focus();
                } else if (returnFocus && restoreFocus) {
                    restoreFocus.focus();
                    restoreFocus = null;
                }
            }

            openButton.addEventListener('click', function () { setNavigation(true, false); });
            closeButtons.forEach(function (button) {
                button.addEventListener('click', function () { setNavigation(false, true); });
            });
            sidebar.querySelectorAll('a').forEach(function (link) {
                link.addEventListener('click', function () {
                    if (!desktop.matches) setNavigation(false, false);
                });
            });
            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape' && shell.classList.contains('is-nav-open')) setNavigation(false, true);
                if (event.key !== 'Tab' || !shell.classList.contains('is-nav-open')) return;
                const focusable = Array.from(sidebar.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'));
                if (!focusable.length) return;
                const first = focusable[0], last = focusable[focusable.length - 1];
                if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
                else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
            });
            desktop.addEventListener('change', function () { setNavigation(false, false); });
            setNavigation(false, false);
        }());

        document.addEventListener('submit', function (event) {
            if (event.target.matches('[data-no-loader]')) return;
            if (event.defaultPrevented || event.target.method.toLowerCase() !== 'post') return;
            document.getElementById('submit-loader').hidden = false;
            event.target.querySelectorAll('button[type="submit"], button:not([type])').forEach(function (button) { button.disabled = true; });
        });

        const toast = document.getElementById('response-toast');
        if (toast) window.setTimeout(function () { toast.remove(); }, 7000);

        (function () {
            const networkStatus = document.getElementById('network-status');
            function syncConnection() {
                networkStatus.hidden = navigator.onLine;
                document.documentElement.classList.toggle('is-offline', !navigator.onLine);
            }
            window.addEventListener('online', syncConnection);
            window.addEventListener('offline', syncConnection);
            syncConnection();
            if ('serviceWorker' in navigator) {
                window.addEventListener('load', function () {
                    navigator.serviceWorker.register('/service-worker.js?v=2', { updateViaCache: 'none' }).then(function (registration) { registration.update(); }).catch(function () {});
                });
            }
        }());
    </script>
    @stack('scripts')
</body>
</html>
