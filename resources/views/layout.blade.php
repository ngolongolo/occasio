<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="referrer" content="no-referrer">
    <title>@yield('title','Okesheni · Every invitation, beautifully connected')</title>
    <link rel="icon" type="image/png" href="/assets/logo/favicon.png"><link rel="stylesheet" href="/assets/style.css"><link rel="stylesheet" href="/assets/guests.css"><link rel="stylesheet" href="/assets/landing.css">
</head>
<body>
    <nav><a href="/"><img src="/assets/logo/logo_main.png" alt="Okesheni"></a><div class="links">@auth<a href="/dashboard">My events</a><form method="post" action="/logout">@csrf<button class="btn small">Sign out</button></form>@else<a href="/#product">Platform</a><a href="/#clients">Clients</a><a href="/#partners">Partners</a><a href="/login">Log in</a><a class="btn small" href="/register">Get started ↗</a>@endauth</div></nav>

    @if(session('success') || $errors->any() || session('import_errors'))
        <div id="response-toast" class="response-toast {{ $errors->any() || session('import_errors') ? 'is-error' : 'is-success' }}" role="status" aria-live="polite">
            <div>@if(session('success'))<strong>{{ session('success') }}</strong>@endif @foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach @foreach(session('import_errors',[]) as $error)<div>{{ $error }}</div>@endforeach</div>
            <button type="button" aria-label="Close message" onclick="this.parentElement.remove()">×</button>
        </div>
    @endif

    @yield('content')
    <footer><img src="/assets/logo/logo_large.png" alt="Okesheni"><span>© {{ date('Y') }} Okesheni</span><span>Professional events, beautifully coordinated.</span></footer>
    <div id="submit-loader" class="submit-loader" hidden aria-live="assertive" aria-label="Processing request"><div class="spinner"></div><strong>Processing…</strong></div>
    <script>
        document.addEventListener('submit', function (event) {
            if (event.defaultPrevented || event.target.method.toLowerCase() !== 'post') return;
            document.getElementById('submit-loader').hidden = false;
            event.target.querySelectorAll('button[type="submit"], button:not([type])').forEach(function (button) { button.disabled = true; });
        });
        const selectAll = document.getElementById('select-all-guests');
        if (selectAll) selectAll.addEventListener('change', function () {
            document.querySelectorAll('.guest-selector').forEach(function (box) { box.checked = selectAll.checked; });
        });
        const toast = document.getElementById('response-toast');
        if (toast) window.setTimeout(function () { toast.remove(); }, 7000);
    </script>
</body>
</html>
