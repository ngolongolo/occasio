<nav class="admin-section-nav" aria-label="Super Admin controls">
    <a class="{{ request()->routeIs('admin.index')?'is-active':'' }}" href="{{ route('admin.index') }}">Overview</a>
    <a class="{{ request()->routeIs('admin.users')?'is-active':'' }}" href="{{ route('admin.users') }}">Users</a>
    <a class="{{ request()->routeIs('admin.events')?'is-active':'' }}" href="{{ route('admin.events') }}">Events</a>
    <a class="{{ request()->routeIs('admin.deliveries')?'is-active':'' }}" href="{{ route('admin.deliveries') }}">Deliveries</a>
    <a class="{{ request()->routeIs('admin.payments')?'is-active':'' }}" href="{{ route('admin.payments') }}">Payments</a>
</nav>
