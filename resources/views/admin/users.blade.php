@extends('layout')
@section('title', 'User control · Super Admin')
@section('content')
<main class="admin-console">
    <header class="admin-page-head"><div><span class="eyebrow">Access control</span><h1>Users and roles</h1><p>Review organiser accounts and grant platform-wide access only when required.</p></div><span>{{ $users->total() }} accounts</span></header>
    @include('partials.alerts')
    @include('admin.nav')
    <section class="admin-panel">
        <form class="admin-filters" method="get"><div><label for="admin-user-search">Search accounts</label><input id="admin-user-search" type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Name or email"></div><div><label for="admin-role-filter">Role</label><select id="admin-role-filter" name="role"><option value="">All roles</option><option value="organiser" @selected(($filters['role']??'')==='organiser')>Organiser</option><option value="super_admin" @selected(($filters['role']??'')==='super_admin')>Super Admin</option></select></div><button type="submit">Apply filters</button><a class="btn secondary" href="{{ route('admin.users') }}">Reset</a></form>
        <div class="table-wrap"><table class="admin-table"><thead><tr><th>Account</th><th>Role</th><th>Events</th><th>Joined</th><th>Access control</th></tr></thead><tbody>@forelse($users as $user)<tr><td><strong>{{ $user->name }}</strong><small>{{ $user->email }}</small></td><td><span class="admin-role role-{{ $user->role }}">{{ str_replace('_',' ',$user->role) }}</span></td><td>{{ $user->events_count }}</td><td>{{ $user->created_at->format('d M Y') }}</td><td><form class="admin-role-form" method="post" action="{{ route('admin.users.role',$user) }}">@csrf @method('put')<select name="role" aria-label="Role for {{ $user->name }}"><option value="organiser" @selected($user->role==='organiser')>Organiser</option><option value="super_admin" @selected($user->role==='super_admin')>Super Admin</option></select><button type="submit">Update</button></form></td></tr>@empty<tr><td colspan="5">No accounts match these filters.</td></tr>@endforelse</tbody></table></div>
        {{ $users->links('partials.pagination') }}
    </section>
</main>
@endsection
