@extends('admin.layout')
@section('title', 'Administrative audit log · System Admin')
@section('admin-content')
    <header class="admin-page-head">
        <div><span class="eyebrow">Administration</span><h1>Audit log</h1><p>An immutable record of privileged role and organiser-verification decisions.</p></div>
        <span>{{ number_format($logs->total()) }} actions</span>
    </header>

    <section class="admin-panel">
        <form class="admin-filters" method="get">
            <div><label for="audit-search">Search activity</label><input id="audit-search" type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Administrator, organiser or action"></div>
            <div><label for="audit-action">Action</label><select id="audit-action" name="action"><option value="">All actions</option>@foreach($actions as $action)<option value="{{ $action }}" @selected(($filters['action']??'')===$action)>{{ str_replace(['.','_'],' ',ucwords($action,'.')) }}</option>@endforeach</select></div>
            <button type="submit">Apply filters</button><a class="btn secondary" href="{{ route('admin.audit.index') }}">Reset</a>
        </form>

        <div class="table-wrap"><table class="admin-table">
            <thead><tr><th>Time</th><th>Administrator</th><th>Action</th><th>Subject</th><th>Decision details</th></tr></thead>
            <tbody>@forelse($logs as $log)
                <tr>
                    <td><strong>{{ $log->created_at->format('d M Y') }}</strong><small>{{ $log->created_at->format('g:i:s A') }} EAT</small></td>
                    <td><strong>{{ $log->actor?->name ?: 'Removed administrator' }}</strong><small>{{ $log->actor?->email ?: $log->actor?->phone }}</small></td>
                    <td><span class="admin-role role-super_admin">{{ str_replace(['.','_'],' ',ucwords($log->action,'.')) }}</span></td>
                    <td><strong>{{ $log->subject?->name ?: 'Platform record' }}</strong><small>{{ $log->subject?->email ?: $log->subject?->phone }}</small></td>
                    <td>
                        @if($log->reason)<strong>{{ $log->reason }}</strong>@endif
                        @php($changed=collect($log->after_state??[])->filter(fn($value,$key)=>data_get($log->before_state??[],$key)!==$value))
                        @if($changed->isNotEmpty())<details><summary>View {{ $changed->count() }} changed field{{ $changed->count()===1?'':'s' }}</summary><dl class="admin-audit-diff">@foreach($changed as $key=>$value)<div><dt>{{ str_replace('_',' ',$key) }}</dt><dd>{{ is_bool($value)?($value?'Yes':'No'):(is_scalar($value)&&$value!==null?(string)$value:'—') }}</dd></div>@endforeach</dl></details>@endif
                    </td>
                </tr>
            @empty<tr><td colspan="5">No administrative actions match these filters.</td></tr>@endforelse</tbody>
        </table></div>
        {{ $logs->links('partials.pagination') }}
    </section>
@endsection
