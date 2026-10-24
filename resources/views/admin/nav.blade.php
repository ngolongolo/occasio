<nav class="admin-sidebar-nav" aria-label="System Admin controls">
    <div class="admin-nav-group">
        <span class="admin-nav-label">{{ __('ui.admin.workspace') }}</span>
        <a class="{{ request()->routeIs('admin.index') ? 'is-active' : '' }}" href="{{ route('admin.index') }}" @if(request()->routeIs('admin.index')) aria-current="page" @endif>
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 13h6V4H4v9Zm10 7h6v-9h-6v9ZM4 20h6v-3H4v3Zm10-13h6V4h-6v3Z"/></svg>
            <span><strong>{{ __('ui.admin.overview') }}</strong><small>{{ __('ui.admin.overview_copy') }}</small></span>
        </a>
    </div>

    <div class="admin-nav-group">
        <span class="admin-nav-label">{{ __('ui.admin.trust') }}</span>
        @if(Route::has('admin.verifications.index'))
            <a class="{{ request()->routeIs('admin.verifications.*') ? 'is-active' : '' }}" href="{{ route('admin.verifications.index') }}" @if(request()->routeIs('admin.verifications.*')) aria-current="page" @endif>
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 5 6v5c0 4.5 2.8 8.1 7 10 4.2-1.9 7-5.5 7-10V6l-7-3Zm-3 9 2 2 4-4"/></svg>
                <span><strong>{{ __('ui.admin.verification') }}</strong><small>{{ __('ui.admin.verification_copy') }}</small></span>
            </a>
        @endif
        <a class="{{ request()->routeIs('admin.users*') ? 'is-active' : '' }}" href="{{ route('admin.users') }}" @if(request()->routeIs('admin.users*')) aria-current="page" @endif>
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M16 20v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 10a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm13 10v-2a4 4 0 0 0-3-3.9M16 2.1a4 4 0 0 1 0 7.8"/></svg>
            <span><strong>{{ __('ui.admin.organisers') }}</strong><small>{{ __('ui.admin.organisers_copy') }}</small></span>
        </a>
    </div>

    <div class="admin-nav-group">
        <span class="admin-nav-label">{{ __('ui.admin.operations') }}</span>
        <a class="{{ request()->routeIs('admin.events') ? 'is-active' : '' }}" href="{{ route('admin.events') }}" @if(request()->routeIs('admin.events')) aria-current="page" @endif>
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 2v4M18 2v4M3 9h18M5 4h14a2 2 0 0 1 2 2v14H3V6a2 2 0 0 1 2-2Z"/></svg>
            <span><strong>{{ __('ui.admin.events') }}</strong><small>{{ __('ui.admin.events_copy') }}</small></span>
        </a>
        <a class="{{ request()->routeIs('admin.deliveries') ? 'is-active' : '' }}" href="{{ route('admin.deliveries') }}" @if(request()->routeIs('admin.deliveries')) aria-current="page" @endif>
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m22 2-7 20-4-9-9-4 20-7ZM11 13 22 2"/></svg>
            <span><strong>{{ __('ui.admin.deliveries') }}</strong><small>{{ __('ui.admin.deliveries_copy') }}</small></span>
        </a>
    </div>

    <div class="admin-nav-group">
        <span class="admin-nav-label">{{ __('ui.admin.finance') }}</span>
        <a class="{{ request()->routeIs('admin.payments') ? 'is-active' : '' }}" href="{{ route('admin.payments') }}" @if(request()->routeIs('admin.payments')) aria-current="page" @endif>
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 6h18v12H3V6Zm0 4h18M7 15h4"/></svg>
            <span><strong>{{ __('ui.admin.payments') }}</strong><small>{{ __('ui.admin.payments_copy') }}</small></span>
        </a>
        @if(Route::has('admin.settlements'))
            <a class="{{ request()->routeIs('admin.settlements*') ? 'is-active' : '' }}" href="{{ route('admin.settlements') }}" @if(request()->routeIs('admin.settlements*')) aria-current="page" @endif>
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 10h18M5 10v8M9 10v8M15 10v8M19 10v8M3 18h18M12 3l9 5H3l9-5Z"/></svg>
                <span><strong>{{ __('ui.admin.settlements') }}</strong><small>{{ __('ui.admin.settlements_copy') }}</small></span>
            </a>
        @endif
        @if(Route::has('admin.pledges'))
            <a class="{{ request()->routeIs('admin.pledges*') ? 'is-active' : '' }}" href="{{ route('admin.pledges') }}" @if(request()->routeIs('admin.pledges*')) aria-current="page" @endif>
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 21s-8-4.4-8-11a4.5 4.5 0 0 1 8-2.8A4.5 4.5 0 0 1 20 10c0 6.6-8 11-8 11Z"/></svg>
                <span><strong>{{ __('ui.admin.pledges') }}</strong><small>{{ __('ui.admin.pledges_copy') }}</small></span>
            </a>
        @endif
    </div>

    @if(Route::has('admin.audit.index'))
        <div class="admin-nav-group">
            <span class="admin-nav-label">{{ __('ui.admin.administration') }}</span>
            <a class="{{ request()->routeIs('admin.audit.*') ? 'is-active' : '' }}" href="{{ route('admin.audit.index') }}" @if(request()->routeIs('admin.audit.*')) aria-current="page" @endif>
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 5H5v16h14V5h-4M9 3h6v4H9V3Zm0 9h6M9 16h6M6 12h.01M6 16h.01"/></svg>
                <span><strong>{{ __('ui.admin.audit') }}</strong><small>{{ __('ui.admin.audit_copy') }}</small></span>
            </a>
        </div>
    @endif
</nav>
