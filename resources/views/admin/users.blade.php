@extends('admin.layout')
@section('title', 'Organiser control · System Admin')
@section('admin-content')
    <header class="admin-page-head">
        <div><span class="eyebrow">Access and trust</span><h1>Users and verification</h1><p>Manage platform roles separately from the identity badges shown to event guests.</p></div>
        <span>{{ $users->total() }} accounts</span>
    </header>
    @include('partials.alerts')
    <section class="admin-panel">
        <form class="admin-filters" method="get">
            <div><label for="admin-user-search">Search accounts</label><input id="admin-user-search" type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Name, email or mobile"></div>
            <div><label for="admin-role-filter">Role</label><select id="admin-role-filter" name="role"><option value="">All roles</option><option value="organiser" @selected(($filters['role']??'')==='organiser')>Organiser</option><option value="super_admin" @selected(($filters['role']??'')==='super_admin')>System Admin</option></select></div>
            <div><label for="admin-verification-filter">Verification</label><select id="admin-verification-filter" name="verification"><option value="">All statuses</option>@foreach(['unverified'=>'Standard','pending'=>'Pending','changes_requested'=>'Changes requested','approved'=>'Approved','rejected'=>'Rejected','suspended'=>'Suspended','expired'=>'Expired'] as $value=>$label)<option value="{{ $value }}" @selected(($filters['verification']??'')===$value)>{{ $label }}</option>@endforeach</select></div>
            <button type="submit">Apply filters</button><a class="btn secondary" href="{{ route('admin.users') }}">Reset</a>
        </form>

        <div class="table-wrap"><table class="admin-table admin-user-table">
            <thead><tr><th>Account</th><th>Role</th><th>Organiser trust</th><th>Events</th><th>Controls</th></tr></thead>
            <tbody>@forelse($users as $user)
                @php
                    $verification = $user->organiserVerification;
                    $verificationStatus = $verification?->effectiveStatus() ?? 'unverified';
                @endphp
                <tr>
                    <td><strong>{{ $user->name }}</strong><small>{{ $user->email ?: $user->phone ?: 'No contact provided' }}</small><small>Joined {{ $user->created_at->format('d M Y') }}</small></td>
                    <td><span class="admin-role role-{{ $user->role }}">{{ $user->isSuperAdmin() ? 'System Admin' : 'Organiser' }}</span></td>
                    <td><span class="verification-state state-{{ $verificationStatus }}">{{ $verification?->badgeLabel() ?: ($verificationStatus === 'unverified' ? 'Standard organiser' : ucfirst($verificationStatus)) }}</span>@if($verification?->public_name)<small>{{ $verification->public_name }}</small>@endif</td>
                    <td>{{ $user->events_count }}</td>
                    <td>
                        <details class="admin-user-controls">
                            <summary>Manage account</summary>
                            <div class="admin-control-grid">
                                <form class="admin-role-form" method="post" action="{{ route('admin.users.role',$user) }}">@csrf @method('put')<div><label>Platform role</label><select name="role" aria-label="Role for {{ $user->name }}"><option value="organiser" @selected($user->role==='organiser')>Organiser</option><option value="super_admin" @selected($user->role==='super_admin')>System Admin</option></select></div><div><label>Reason for access change</label><input name="reason" required minlength="5" maxlength="500" placeholder="Why is this role changing?"></div><button type="submit">Update role</button></form>

                                @if(!$user->isSuperAdmin())
                                    <div class="admin-verification-form">
                                        <h3>Organiser verification</h3>
                                        @if($verification)
                                            <p>Identity decisions and payout approval are managed independently with a permanent audit trail.</p>
                                            <a class="btn secondary" href="{{ route('admin.verifications.show', $user) }}">Open verification review →</a>
                                        @else
                                            <p class="muted">This organiser has not submitted a verification application.</p>
                                        @endif
                                    </div>
                                @endif
                            </div>
                        </details>
                    </td>
                </tr>
            @empty<tr><td colspan="5">No accounts match these filters.</td></tr>@endforelse</tbody>
        </table></div>
        {{ $users->links('partials.pagination') }}
    </section>
@endsection
