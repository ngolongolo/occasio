@extends('admin.layout')
@section('title', 'Review '.$organiser->name.' · System Admin')
@section('admin-content')
@php
    $status = $verification->effectiveStatus();
    $version = $verification->reviewVersion();
    $canApproveBusiness = $verification->organiser_type !== 'individual' && filled($verification->registration_number);
@endphp
<div class="admin-verification-review">
    <header class="admin-page-head">
        <div>
            <a class="create-back" href="{{ route('admin.verifications.index', ['status' => $status === 'pending' ? 'pending' : 'all']) }}">← Verification queue</a>
            <span class="eyebrow">Organiser review</span>
            <h1>{{ $verification->public_name ?: $organiser->name }}</h1>
            <p>{{ $organiser->email ?: $organiser->phone }} · Submitted {{ $verification->submitted_at?->diffForHumans() ?: 'date not recorded' }}</p>
        </div>
        <div class="admin-review-status">
            <small>Current trust state</small>
            <strong class="verification-state state-{{ $status }}">{{ ucfirst(str_replace('_', ' ', $status)) }}</strong>
            <span>{{ ucfirst($verification->verification_level) }} review · {{ ucfirst($verification->organiser_type) }}</span>
        </div>
    </header>

    @include('partials.alerts')
    <section class="admin-metrics" aria-label="Organiser exposure">
        <article><span>Events</span><strong>{{ $exposure['events'] }}</strong><small>{{ $exposure['public_events'] }} public · {{ $exposure['paid_events'] }} paid</small></article>
        <article><span>Successful payments</span><strong>{{ $exposure['payments_paid_count'] }}</strong><small>@forelse($exposure['payments_by_currency'] as $position){{ $position->currency }} {{ number_format((float) $position->total, 2) }}{{ !$loop->last ? ' · ' : '' }}@empty No confirmed collections @endforelse</small></article>
        <article><span>Open requests</span><strong>{{ $exposure['payments_open'] }}</strong><small>{{ $exposure['payments_failed'] }} failed requests</small></article>
        <article><span>Settlements</span><strong>{{ $exposure['settlements_count'] }}</strong><small>@forelse($exposure['settlements_by_currency'] as $position){{ $position->currency }} {{ number_format((float) $position->total, 2) }}{{ !$loop->last ? ' · ' : '' }}@empty No paid or in-progress settlements @endforelse</small></article>
    </section>

    <div class="admin-review-grid">
        <div class="admin-review-main">
            <section class="admin-panel">
                <div class="admin-panel-heading"><div><span class="eyebrow">Application</span><h2>Public and legal identity</h2></div><span class="verification-state state-{{ $status }}">{{ ucfirst(str_replace('_', ' ', $status)) }}</span></div>
                <dl class="admin-detail-list">
                    <div><dt>Account name</dt><dd>{{ $organiser->name }}</dd></div>
                    <div><dt>Account email</dt><dd>{{ $organiser->email ?: 'Not provided' }}</dd></div>
                    <div><dt>Account mobile</dt><dd>{{ $organiser->phone ?: 'Not provided' }}</dd></div>
                    <div><dt>Public organiser name</dt><dd>{{ $verification->public_name ?: 'Not provided' }}</dd></div>
                    <div><dt>Public support email</dt><dd>{{ $verification->public_contact_email ?: 'Not provided' }}</dd></div>
                    <div><dt>Public support phone</dt><dd>{{ $verification->public_contact_phone ?: 'Not provided' }}</dd></div>
                    <div><dt>Organiser type</dt><dd>{{ ucfirst($verification->organiser_type) }}</dd></div>
                    <div class="is-private"><dt>Legal name <span>Private</span></dt><dd>{{ $verification->legal_name ?: 'Not provided' }}</dd></div>
                    <div class="is-private"><dt>Registration number <span>Private</span></dt><dd>{{ $verification->registration_number ?: 'Not provided' }}</dd></div>
                    <div class="is-private"><dt>Tax identifier <span>Private</span></dt><dd>{{ $verification->tax_identifier ?: 'Not provided' }}</dd></div>
                </dl>
                <p class="admin-privacy-note">Legal and tax fields are decrypted only for this authorised review page. They are excluded from audit snapshots and guest-facing profiles.</p>
            </section>

            <section class="admin-panel">
                <div class="admin-panel-heading"><div><span class="eyebrow">Operations</span><h2>Events and payout exposure</h2></div></div>
                <div class="admin-payout-accounts">
                    <h3>Configured payout accounts</h3>
                    @forelse($payoutAccounts as $account)<div><strong>{{ $account['type'] ?: 'Unspecified network' }}</strong><span>{{ $account['masked_number'] }}</span></div>@empty<p class="muted">No payout account is configured on an event.</p>@endforelse
                </div>
                <div class="admin-activity-list">
                    @forelse($recentEvents as $event)
                        <a href="{{ route('events.show', $event) }}"><span class="admin-activity-icon">◇</span><div><strong>{{ $event->title }}</strong><small>{{ ucfirst(str_replace('_', ' ', $event->visibility)) }} · {{ $event->invitees_count }} participants</small></div><time>{{ $event->created_at->format('d M Y') }}</time></a>
                    @empty<p class="muted">No events created yet.</p>@endforelse
                </div>
            </section>

            <section class="admin-panel">
                <div class="admin-panel-heading"><div><span class="eyebrow">Immutable audit trail</span><h2>Review timeline</h2></div></div>
                <ol class="admin-audit-timeline">
                    @forelse($timeline as $entry)
                        @php
                            $before = $entry->before_state ?? [];
                            $after = $entry->after_state ?? [];
                            $changed = collect(array_unique(array_merge(array_keys($before), array_keys($after))))->filter(fn($key) => ($before[$key] ?? null) !== ($after[$key] ?? null))->values();
                        @endphp
                        <li>
                            <span class="admin-audit-marker" aria-hidden="true"></span>
                            <div><strong>{{ ucfirst(str_replace(['organiser_verification.', '_'], ['', ' '], $entry->action)) }}</strong><small>{{ $entry->actor?->name ?: 'Deleted administrator' }} · {{ $entry->created_at->format('d M Y, H:i') }}</small>
                            @if($entry->reason)<p>{{ $entry->reason }}</p>@endif
                            @if($changed->isNotEmpty())<span>Changed: {{ $changed->map(fn($key) => str_replace('_', ' ', $key))->join(', ') }}</span>@endif</div>
                        </li>
                    @empty<li><p class="muted">No administrator decisions have been recorded yet.</p></li>@endforelse
                </ol>
            </section>
        </div>

        <aside class="admin-review-actions">
            <section class="admin-panel">
                <span class="eyebrow">Decision</span><h2>Approve verification</h2>
                <p>Approval confirms the submitted identity, not the quality or outcome of an event.</p>
                <form method="post" action="{{ route('admin.verifications.review', $organiser) }}">
                    @csrf @method('put')
                    <input type="hidden" name="verification_version" value="{{ $version }}">
                    <label for="approval-expiry">Badge expiry</label><input id="approval-expiry" type="date" name="expires_at" min="{{ now()->addDay()->format('Y-m-d') }}" value="{{ old('expires_at', $verification->expires_at?->format('Y-m-d') ?: now()->addYear()->format('Y-m-d')) }}">
                    <label for="approval-note">Decision note <span class="muted">(optional)</span></label><textarea id="approval-note" name="reason" maxlength="2000" placeholder="Evidence checked or internal context">{{ old('reason') }}</textarea>
                    <div class="admin-action-buttons">
                        <button type="submit" name="action" value="approve_identity">Approve identity</button>
                        <button type="submit" name="action" value="approve_business" @disabled(!$canApproveBusiness)>Approve organisation</button>
                    </div>
                    @if(!$canApproveBusiness)<small>Organisation approval needs a non-individual profile and registration number.</small>@endif
                </form>
            </section>

            <section class="admin-panel">
                <span class="eyebrow">Financial control</span><h2>Payout account</h2>
                <p>Verify the payout account name only after identity approval and account-name evidence.</p>
                @if($verification->payout_name_verified_at)<div class="verification-approved-note"><strong>Account name matched</strong><p>Verified {{ $verification->payout_name_verified_at->format('d M Y, H:i') }}.</p></div>@endif
                <form method="post" action="{{ route('admin.verifications.review', $organiser) }}">
                    @csrf @method('put')<input type="hidden" name="verification_version" value="{{ $version }}"><input type="hidden" name="action" value="verify_payout">
                    <label class="admin-check"><input type="checkbox" name="payout_confirmation" value="1" required> I matched the account name to this organiser.</label>
                    <button type="submit" @disabled(!$verification->isApproved() || $payoutAccounts->isEmpty())>Verify payout account</button>
                </form>
                @if($verification->payout_name_verified_at)
                    <form method="post" action="{{ route('admin.verifications.review', $organiser) }}">
                        @csrf @method('put')<input type="hidden" name="verification_version" value="{{ $version }}"><input type="hidden" name="action" value="revoke_payout">
                        <label for="revoke-payout-reason">Reason for revocation</label><textarea id="revoke-payout-reason" name="reason" required maxlength="2000"></textarea><button class="btn secondary" type="submit">Revoke payout verification</button>
                    </form>
                @endif
            </section>

            <section class="admin-panel admin-adverse-actions">
                <span class="eyebrow">Needs attention</span><h2>Request, reject or suspend</h2>
                <p>A clear reason is required and will be available to the organiser.</p>
                <form method="post" action="{{ route('admin.verifications.review', $organiser) }}">
                    @csrf @method('put')<input type="hidden" name="verification_version" value="{{ $version }}">
                    <label for="adverse-reason">Required reason</label><textarea id="adverse-reason" name="reason" required maxlength="2000" placeholder="Explain what must change or why access is restricted.">{{ old('reason') }}</textarea>
                    <div class="admin-action-buttons"><button class="btn secondary" type="submit" name="action" value="request_changes">Request changes</button><button class="btn secondary" type="submit" name="action" value="reject">Reject</button><button class="btn danger" type="submit" name="action" value="suspend">Suspend</button></div>
                </form>
            </section>

            @if($verification->isApproved())
            <section class="admin-panel">
                <span class="eyebrow">Validity</span><h2>Change expiry</h2>
                <form method="post" action="{{ route('admin.verifications.review', $organiser) }}">
                    @csrf @method('put')<input type="hidden" name="verification_version" value="{{ $version }}"><input type="hidden" name="action" value="set_expiry">
                    <label for="new-expiry">New expiry date</label><input id="new-expiry" type="date" name="expires_at" min="{{ now()->addDay()->format('Y-m-d') }}" value="{{ $verification->expires_at?->format('Y-m-d') }}" required>
                    <button class="btn secondary" type="submit">Update expiry</button>
                </form>
            </section>
            @endif
        </aside>
    </div>
</div>
@endsection
