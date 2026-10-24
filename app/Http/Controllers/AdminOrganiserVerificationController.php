<?php

namespace App\Http\Controllers;

use App\Models\AdminAuditLog;
use App\Models\Event;
use App\Models\EventSettlement;
use App\Models\OrganiserVerification;
use App\Models\ParticipationPayment;
use App\Models\User;
use App\Services\AdminAuditLogger;
use App\Services\TanzanianPhoneNumber;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminOrganiserVerificationController
{
    private const QUEUE_STATUSES = [
        'all',
        OrganiserVerification::STATUS_PENDING,
        OrganiserVerification::STATUS_CHANGES_REQUESTED,
        OrganiserVerification::STATUS_APPROVED,
        OrganiserVerification::STATUS_REJECTED,
        OrganiserVerification::STATUS_SUSPENDED,
        OrganiserVerification::STATUS_EXPIRED,
    ];

    private const REVIEW_ACTIONS = [
        'approve_identity',
        'approve_business',
        'verify_payout',
        'revoke_payout',
        'request_changes',
        'reject',
        'suspend',
        'set_expiry',
    ];

    private const REASON_REQUIRED_ACTIONS = [
        'revoke_payout',
        'request_changes',
        'reject',
        'suspend',
    ];

    public function __construct(private readonly AdminAuditLogger $auditLogger) {}

    public function index(Request $request)
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(self::QUEUE_STATUSES)],
            'level' => ['nullable', Rule::in(OrganiserVerification::LEVELS)],
            'type' => ['nullable', Rule::in(OrganiserVerification::TYPES)],
        ]);
        $filters['status'] = $filters['status'] ?? OrganiserVerification::STATUS_PENDING;

        $query = OrganiserVerification::query()
            ->with(['user:id,name,email,phone,created_at', 'reviewer:id,name']);

        // withCount cannot count a user's hasMany through this verification relation
        // portably, so use a correlated count that works in both SQLite tests and MySQL.
        $query->select('organiser_verifications.*')->selectSub(
            Event::selectRaw('count(*)')->whereColumn('events.user_id', 'organiser_verifications.user_id'),
            'events_count'
        );

        $query->when($filters['q'] ?? null, function (Builder $query, string $search): void {
            $normalizedPhone = TanzanianPhoneNumber::normalize($search);
            $query->where(function (Builder $query) use ($search, $normalizedPhone): void {
                $query->where('public_name', 'like', "%{$search}%")
                    ->orWhereHas('user', fn (Builder $user) => $user
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%")
                        ->when($normalizedPhone, fn (Builder $user) => $user->orWhere('phone', $normalizedPhone)));
            });
        })->when($filters['level'] ?? null, fn (Builder $query, string $level) => $query->where('verification_level', $level))
            ->when($filters['type'] ?? null, fn (Builder $query, string $type) => $query->where('organiser_type', $type));

        $this->applyStatus($query, $filters['status']);

        if ($filters['status'] === OrganiserVerification::STATUS_PENDING) {
            $query->orderByRaw('submitted_at is null')->orderBy('submitted_at')->orderBy('id');
        } else {
            $query->latest('updated_at')->latest('id');
        }

        $verifications = $query->paginate(25)->withQueryString();

        return view('admin.verifications.index', [
            'verifications' => $verifications,
            'filters' => $filters,
            'statusCounts' => $this->statusCounts(),
        ]);
    }

    public function show(User $user)
    {
        abort_if($user->isSuperAdmin(), 404);

        $verification = $user->organiserVerification()->with('reviewer:id,name')->firstOrFail();
        $eventsQuery = Event::where('user_id', $user->id);
        $paymentQuery = ParticipationPayment::whereHas('invitee.event', fn (Builder $query) => $query->where('user_id', $user->id));
        $settlementQuery = EventSettlement::whereHas('event', fn (Builder $query) => $query->where('user_id', $user->id));
        $paidByCurrency = (clone $paymentQuery)->where('status', 'paid')
            ->selectRaw('currency, count(*) as payment_count, sum(amount) as total')
            ->groupBy('currency')->orderBy('currency')->get();
        $settledByCurrency = (clone $settlementQuery)->whereIn('status', ['initiated', 'pending', 'paid'])
            ->selectRaw('currency, count(*) as settlement_count, sum(amount) as total')
            ->groupBy('currency')->orderBy('currency')->get();

        $exposure = [
            'events' => (clone $eventsQuery)->count(),
            'public_events' => (clone $eventsQuery)->where('visibility', '!=', 'invite_only')->count(),
            'paid_events' => (clone $eventsQuery)->whereIn('participation_payment_timing', ['pay_now', 'pay_at_event'])->count(),
            'payments_paid_count' => (int) $paidByCurrency->sum('payment_count'),
            'payments_by_currency' => $paidByCurrency,
            'payments_open' => (clone $paymentQuery)->whereIn('status', ['initiated', 'pending'])->count(),
            'payments_failed' => (clone $paymentQuery)->whereIn('status', ['failed', 'request_failed'])->count(),
            'settlements_count' => (int) $settledByCurrency->sum('settlement_count'),
            'settlements_by_currency' => $settledByCurrency,
        ];

        $payoutAccounts = Event::where('user_id', $user->id)
            ->whereNotNull('payout_number')
            ->select(['payout_payment_type', 'payout_number'])
            ->distinct()->get()->map(fn (Event $event) => [
                'type' => $event->payout_payment_type,
                'masked_number' => $this->maskAccount($event->payout_number),
            ]);

        return view('admin.verifications.show', [
            'organiser' => $user,
            'verification' => $verification,
            'exposure' => $exposure,
            'payoutAccounts' => $payoutAccounts,
            'recentEvents' => $eventsQuery->withCount('invitees')->latest()->limit(8)->get(),
            'timeline' => AdminAuditLog::forSubject($user)->with('actor:id,name')->latest('id')->limit(30)->get(),
        ]);
    }

    public function review(Request $request, User $user)
    {
        abort_if($user->isSuperAdmin(), 404);

        $data = $request->validate([
            'action' => ['required', Rule::in(self::REVIEW_ACTIONS)],
            'verification_version' => ['required', 'string', 'max:80'],
            'reason' => ['nullable', 'string', 'max:2000', Rule::requiredIf(in_array($request->input('action'), self::REASON_REQUIRED_ACTIONS, true))],
            'expires_at' => ['nullable', 'date', 'after:today', Rule::requiredIf($request->input('action') === 'set_expiry')],
            'payout_confirmation' => ['nullable', 'accepted_if:action,verify_payout'],
        ]);

        $verification = DB::transaction(function () use ($request, $user, $data): OrganiserVerification {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $verification = OrganiserVerification::where('user_id', $user->id)->lockForUpdate()->firstOrFail();

            if (! hash_equals($verification->reviewVersion(), $data['verification_version'])) {
                throw ValidationException::withMessages([
                    'action' => 'This application changed after you opened it. Review the latest information before deciding.',
                ]);
            }

            $before = $this->auditSnapshot($verification);
            $this->applyReviewAction($verification, $data, $request->user()->id);
            $verification->save();

            $this->auditLogger->record(
                actor: $request->user(),
                action: 'organiser_verification.'.$data['action'],
                subject: $user,
                auditable: $verification,
                before: $before,
                after: $this->auditSnapshot($verification),
                reason: $data['reason'] ?? null,
                request: $request,
            );

            return $verification;
        }, 3);

        return redirect()->route('admin.verifications.show', $user)
            ->with('success', $this->successMessage($data['action'], $verification));
    }

    /** Preserve the old users/{user}/verification endpoint while making it auditable. */
    public function legacyUpdate(Request $request, User $user)
    {
        abort_if($user->isSuperAdmin(), 404);

        $data = $request->validate([
            'organiser_type' => ['required', Rule::in(OrganiserVerification::TYPES)],
            'verification_level' => ['required', Rule::in(OrganiserVerification::LEVELS)],
            'status' => ['required', Rule::in(OrganiserVerification::STATUSES)],
            'public_name' => ['nullable', 'string', 'max:180'],
            'public_contact_email' => ['nullable', 'email', 'max:255'],
            'public_contact_phone' => ['nullable', 'string', 'regex:/^\+[1-9]\d{7,14}$/', 'max:32'],
            'expires_at' => ['nullable', 'date', 'after:today'],
            'payout_name_verified' => ['nullable', 'boolean'],
            'review_notes' => ['nullable', 'string', 'max:2000', Rule::requiredIf(in_array($request->input('status'), [
                OrganiserVerification::STATUS_CHANGES_REQUESTED,
                OrganiserVerification::STATUS_REJECTED,
                OrganiserVerification::STATUS_SUSPENDED,
            ], true))],
        ]);

        $verification = DB::transaction(function () use ($request, $user, $data): OrganiserVerification {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $verification = OrganiserVerification::where('user_id', $user->id)->lockForUpdate()->first();
            $before = $verification ? $this->auditSnapshot($verification) : [];
            $verification ??= new OrganiserVerification(['user_id' => $user->id]);

            $this->validateLegacyApproval($verification, $data);
            $approved = $data['status'] === OrganiserVerification::STATUS_APPROVED;
            $pending = $data['status'] === OrganiserVerification::STATUS_PENDING;
            if ($data['status'] === OrganiserVerification::STATUS_UNVERIFIED) {
                $data['verification_level'] = OrganiserVerification::LEVEL_NONE;
            }

            $verification->fill($data);
            $verification->user_id = $user->id;
            $verification->reviewed_by = $pending ? null : $request->user()->id;
            $verification->reviewed_at = $pending ? null : now();
            $verification->verified_at = $approved ? ($verification->verified_at ?? now()) : null;
            $verification->payout_name_verified_at = $approved && $request->boolean('payout_name_verified')
                ? ($verification->payout_name_verified_at ?? now())
                : null;
            $verification->save();

            $this->auditLogger->record(
                actor: $request->user(),
                action: 'organiser_verification.legacy_updated',
                subject: $user,
                auditable: $verification,
                before: $before,
                after: $this->auditSnapshot($verification),
                reason: $data['review_notes'] ?? null,
                request: $request,
            );

            return $verification;
        }, 3);

        return back()->with('success', $user->name.' verification is now '.str_replace('_', ' ', $verification->effectiveStatus()).'.');
    }

    private function applyReviewAction(OrganiserVerification $verification, array $data, int $reviewerId): void
    {
        $action = $data['action'];
        $reason = filled($data['reason'] ?? null) ? trim($data['reason']) : null;

        if ($action === 'approve_identity' || $action === 'approve_business') {
            $level = $action === 'approve_business' ? OrganiserVerification::LEVEL_BUSINESS : OrganiserVerification::LEVEL_IDENTITY;
            $this->assertApprovalReady($verification, $level);
            $verification->status = OrganiserVerification::STATUS_APPROVED;
            $verification->verification_level = $level;
            $verification->verified_at = now();
            $verification->expires_at = isset($data['expires_at']) ? $data['expires_at'] : now()->addYear();
            $verification->decision_message = null;
        } elseif ($action === 'verify_payout') {
            if (! $verification->isApproved()) {
                throw ValidationException::withMessages(['action' => 'Approve the organiser before verifying a payout account.']);
            }
            if (! Event::where('user_id', $verification->user_id)->whereNotNull('payout_number')->exists()) {
                throw ValidationException::withMessages(['action' => 'No payout account is configured on this organiser’s events.']);
            }
            $verification->payout_name_verified_at ??= now();
        } elseif ($action === 'revoke_payout') {
            $verification->payout_name_verified_at = null;
            $verification->decision_message = $reason;
        } elseif ($action === 'request_changes') {
            $verification->status = OrganiserVerification::STATUS_CHANGES_REQUESTED;
            $verification->verified_at = null;
            $verification->payout_name_verified_at = null;
            $verification->decision_message = $reason;
        } elseif ($action === 'reject') {
            $verification->status = OrganiserVerification::STATUS_REJECTED;
            $verification->verified_at = null;
            $verification->payout_name_verified_at = null;
            $verification->decision_message = $reason;
        } elseif ($action === 'suspend') {
            $verification->status = OrganiserVerification::STATUS_SUSPENDED;
            $verification->payout_name_verified_at = null;
            $verification->decision_message = $reason;
        } elseif ($action === 'set_expiry') {
            if (! $verification->isApproved()) {
                throw ValidationException::withMessages(['action' => 'Only an approved verification can receive a new expiry date.']);
            }
            $verification->expires_at = $data['expires_at'];
        }

        $verification->reviewed_by = $reviewerId;
        $verification->reviewed_at = now();
        $verification->review_notes = $reason;
    }

    private function assertApprovalReady(OrganiserVerification $verification, string $level): void
    {
        if (blank($verification->public_name)
            || (blank($verification->public_contact_email) && blank($verification->public_contact_phone))) {
            throw ValidationException::withMessages(['action' => 'A public organiser name and at least one public contact are required for approval.']);
        }
        if (blank($verification->legal_name)) {
            throw ValidationException::withMessages(['action' => 'The applicant must provide a legal name before approval.']);
        }
        if ($level === OrganiserVerification::LEVEL_BUSINESS) {
            if ($verification->organiser_type === OrganiserVerification::TYPE_INDIVIDUAL) {
                throw ValidationException::withMessages(['action' => 'An individual profile cannot receive organisation verification.']);
            }
            if (blank($verification->registration_number)) {
                throw ValidationException::withMessages(['action' => 'A registration number is required for organisation verification.']);
            }
        }
    }

    private function validateLegacyApproval(OrganiserVerification $verification, array $data): void
    {
        if ($data['status'] !== OrganiserVerification::STATUS_APPROVED) {
            return;
        }
        if ($data['verification_level'] === OrganiserVerification::LEVEL_NONE) {
            throw ValidationException::withMessages(['verification_level' => 'Choose identity or business verification before approval.']);
        }
        if (blank($data['public_name'] ?? null)
            || (blank($data['public_contact_email'] ?? null) && blank($data['public_contact_phone'] ?? null))) {
            throw ValidationException::withMessages(['public_name' => 'Approved organisers need a public name and at least one public contact.']);
        }
    }

    private function auditSnapshot(OrganiserVerification $verification): array
    {
        return [
            'organiser_type' => $verification->organiser_type,
            'verification_level' => $verification->verification_level,
            'status' => $verification->status,
            'public_name' => $verification->public_name,
            'public_contact_email' => $verification->public_contact_email,
            'public_contact_phone' => $verification->public_contact_phone,
            'reviewed_by' => $verification->reviewed_by,
            'submitted_at' => $verification->submitted_at?->toISOString(),
            'reviewed_at' => $verification->reviewed_at?->toISOString(),
            'verified_at' => $verification->verified_at?->toISOString(),
            'expires_at' => $verification->expires_at?->toISOString(),
            'payout_name_verified' => $verification->payout_name_verified_at !== null,
        ];
    }

    private function applyStatus(Builder $query, string $status): void
    {
        if ($status === 'all') {
            return;
        }
        if ($status === OrganiserVerification::STATUS_EXPIRED) {
            $query->where(fn (Builder $query) => $query
                ->where('status', OrganiserVerification::STATUS_EXPIRED)
                ->orWhere(fn (Builder $query) => $query->where('status', OrganiserVerification::STATUS_APPROVED)->where('expires_at', '<=', now())));
        } elseif ($status === OrganiserVerification::STATUS_APPROVED) {
            $query->where('status', $status)->where(fn (Builder $query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()));
        } else {
            $query->where('status', $status);
        }
    }

    private function statusCounts(): array
    {
        $counts = ['all' => OrganiserVerification::count()];
        foreach (array_slice(self::QUEUE_STATUSES, 1) as $status) {
            $query = OrganiserVerification::query();
            $this->applyStatus($query, $status);
            $counts[$status] = $query->count();
        }

        return $counts;
    }

    private function maskAccount(?string $number): string
    {
        $number = preg_replace('/\s+/', '', (string) $number);
        return strlen($number) <= 4 ? str_repeat('•', strlen($number)) : str_repeat('•', strlen($number) - 4).substr($number, -4);
    }

    private function successMessage(string $action, OrganiserVerification $verification): string
    {
        return match ($action) {
            'approve_identity' => 'Identity verification approved.',
            'approve_business' => 'Organisation verification approved.',
            'verify_payout' => 'Payout account name marked as verified.',
            'revoke_payout' => 'Payout verification revoked.',
            'request_changes' => 'Changes requested from the organiser.',
            'reject' => 'Verification application rejected.',
            'suspend' => 'Organiser verification suspended.',
            'set_expiry' => 'Verification expiry updated.',
            default => 'Verification updated to '.$verification->effectiveStatus().'.',
        };
    }
}
