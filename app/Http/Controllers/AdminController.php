<?php

namespace App\Http\Controllers;

use App\Models\{AdminAuditLog, Delivery, Event, EventSettlement, OrganiserVerification, ParticipationPayment, PledgePayment, User};
use App\Services\{AdminAuditLogger, AdminReportingService, TanzanianPhoneNumber};
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminController
{
    public function index(AdminReportingService $reports)
    {
        return view('admin.index', $reports->dashboard());
    }

    public function users(Request $request)
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', Rule::in(['organiser', 'super_admin'])],
            'verification' => ['nullable', Rule::in(OrganiserVerification::STATUSES)],
        ]);
        $users = User::with('organiserVerification')->withCount('events')->when($filters['q'] ?? null, function ($query, $search) {
            $normalizedPhone = TanzanianPhoneNumber::normalize($search);
            $query->where(fn ($query) => $query
                ->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%")
                ->when($normalizedPhone, fn ($query) => $query->orWhere('phone', $normalizedPhone)));
        })->when($filters['role'] ?? null, fn ($query, $role) => $query->where('role', $role))
            ->when(($filters['verification'] ?? null) === OrganiserVerification::STATUS_UNVERIFIED, fn ($query) => $query->where(function ($query) {
                $query->whereDoesntHave('organiserVerification')->orWhereHas('organiserVerification', fn ($verification) => $verification->where('status', OrganiserVerification::STATUS_UNVERIFIED));
            }))
            ->when(! empty($filters['verification']) && $filters['verification'] !== OrganiserVerification::STATUS_UNVERIFIED, fn ($query) => $query->whereHas('organiserVerification', fn ($verification) => $verification->where('status', $filters['verification'])))
            ->latest()->paginate(20)->withQueryString();

        return view('admin.users', compact('users', 'filters'));
    }

    public function events(Request $request)
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'state' => ['nullable', Rule::in(['upcoming', 'scheduled', 'ongoing', 'completed'])]]);
        $events = Event::with('user')->withCount('invitees')->when($filters['q'] ?? null, function ($query, $search) {
            $query->where(fn ($query) => $query->where('title', 'like', "%{$search}%")->orWhere('host', 'like', "%{$search}%")->orWhere('venue', 'like', "%{$search}%")->orWhereHas('user', fn ($user) => $user->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%")));
        })->when(in_array(($filters['state'] ?? null), ['upcoming', 'scheduled'], true), fn ($query) => $query->where('starts_at', '>', now()))
            ->when(($filters['state'] ?? null) === 'ongoing', fn ($query) => $query->where('starts_at', '<=', now())->whereNotNull('ends_at')->where('ends_at', '>=', now()))
            ->when(($filters['state'] ?? null) === 'completed', fn ($query) => $query->where(fn ($query) => $query->where('ends_at', '<', now())->orWhere(fn ($query) => $query->whereNull('ends_at')->where('starts_at', '<=', now()))))
            ->latest()->paginate(20)->withQueryString();

        return view('admin.events', compact('events', 'filters'));
    }

    public function deliveries(Request $request)
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'channel' => ['nullable', Rule::in(['email', 'sms', 'whatsapp'])], 'status' => ['nullable', 'string', 'max:30']]);
        $deliveries = Delivery::with('invitee.event.user')->when($filters['q'] ?? null, function ($query, $search) {
            $query->whereHas('invitee', fn ($invitee) => $invitee->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%")->orWhereHas('event', fn ($event) => $event->where('title', 'like', "%{$search}%")));
        })->when($filters['channel'] ?? null, fn ($query, $channel) => $query->where('channel', $channel))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->latest()->paginate(25)->withQueryString();

        return view('admin.deliveries', compact('deliveries', 'filters'));
    }

    public function payments(Request $request, AdminReportingService $reports)
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['initiated', 'pending', 'paid', 'failed', 'request_failed', 'superseded'])],
            'currency' => ['nullable', 'string', 'size:3', 'regex:/^[A-Za-z]{3}$/'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);
        $query = ParticipationPayment::query()
            ->with([
                'invitee:id,event_id,name,email,phone',
                'invitee.event:id,uuid,user_id,title',
                'invitee.event.user:id,name,email,phone',
            ])
            ->when($filters['q'] ?? null, function ($query, $search) {
                $query->where(function ($query) use ($search) {
                    $query->where('reference', 'like', "%{$search}%")
                        ->orWhere('provider_payment_reference', 'like', "%{$search}%")
                        ->orWhereHas('invitee', fn ($invitee) => $invitee
                            ->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%")
                            ->orWhere('phone', 'like', "%{$search}%")
                            ->orWhereHas('event', fn ($event) => $event
                                ->where('title', 'like', "%{$search}%")
                                ->orWhereHas('user', fn ($user) => $user
                                    ->where('name', 'like', "%{$search}%")
                                    ->orWhere('email', 'like', "%{$search}%")
                                    ->orWhere('phone', 'like', "%{$search}%"))));
                });
            })
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['currency'] ?? null, fn ($query, $currency) => $query->where('currency', strtoupper($currency)))
            ->when($filters['date_from'] ?? null, fn ($query, $date) => $query->where('created_at', '>=', Carbon::parse($date)->startOfDay()))
            ->when($filters['date_to'] ?? null, fn ($query, $date) => $query->where('created_at', '<=', Carbon::parse($date)->endOfDay()));

        $paymentSummary = $reports->participationPaymentSummary($query);
        $payments = $query->orderByDesc('id')->paginate(25)->withQueryString();
        $currencies = ParticipationPayment::query()->distinct()->orderBy('currency')->pluck('currency');

        return view('admin.payments', compact('payments', 'paymentSummary', 'currencies', 'filters'));
    }

    public function pledges(Request $request)
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['recorded', 'voided'])],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);
        $payments = PledgePayment::with('pledge.invitee.event.user')->when($filters['q'] ?? null, function ($query, $search) {
            $query->where(fn ($query) => $query->where('reference', 'like', "%{$search}%")->orWhereHas('pledge.invitee', fn ($invitee) => $invitee->where('name', 'like', "%{$search}%")->orWhereHas('event', fn ($event) => $event->where('title', 'like', "%{$search}%"))));
        })->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['date_from'] ?? null, fn ($query, $date) => $query->where('payment_date', '>=', $date))
            ->when($filters['date_to'] ?? null, fn ($query, $date) => $query->where('payment_date', '<=', $date))
            ->latest('payment_date')->latest('id')->paginate(25)->withQueryString();

        return view('admin.pledges', compact('payments', 'filters'));
    }

    public function settlements(Request $request, AdminReportingService $reports)
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['initiated', 'pending', 'paid', 'failed', 'reversed'])],
            'currency' => ['nullable', 'string', 'size:3', 'regex:/^[A-Za-z]{3}$/'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);
        $query = EventSettlement::query()
            ->with(['event:id,uuid,user_id,title', 'event.user:id,name,email,phone', 'requester:id,name,email,phone'])
            ->when($filters['q'] ?? null, function ($query, $search) {
                $query->where(function ($query) use ($search) {
                    $query->where('reference', 'like', "%{$search}%")
                        ->orWhere('provider_reference', 'like', "%{$search}%")
                        ->orWhere('account_name', 'like', "%{$search}%")
                        ->orWhere('payment_number', 'like', "%{$search}%")
                        ->orWhereHas('event', fn ($event) => $event
                            ->where('title', 'like', "%{$search}%")
                            ->orWhereHas('user', fn ($user) => $user
                                ->where('name', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%")
                                ->orWhere('phone', 'like', "%{$search}%")));
                });
            })
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['currency'] ?? null, fn ($query, $currency) => $query->where('currency', strtoupper($currency)))
            ->when($filters['date_from'] ?? null, fn ($query, $date) => $query->where('created_at', '>=', Carbon::parse($date)->startOfDay()))
            ->when($filters['date_to'] ?? null, fn ($query, $date) => $query->where('created_at', '<=', Carbon::parse($date)->endOfDay()));

        $settlementSummary = $reports->settlementSummary($query);
        $settlements = $query->orderByDesc('id')->paginate(25)->withQueryString();
        $currencies = EventSettlement::query()->distinct()->orderBy('currency')->pluck('currency');

        return view('admin.settlements', compact('settlements', 'settlementSummary', 'currencies', 'filters'));
    }

    public function audit(Request $request)
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'action' => ['nullable', 'string', 'max:100'],
        ]);
        $logs = AdminAuditLog::query()
            ->with(['actor:id,name,email,phone', 'subject:id,name,email,phone'])
            ->when($filters['q'] ?? null, function ($query, string $search): void {
                $query->where(function ($query) use ($search): void {
                    $query->where('action', 'like', "%{$search}%")
                        ->orWhereHas('actor', fn ($actor) => $actor->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%"))
                        ->orWhereHas('subject', fn ($subject) => $subject->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%"));
                });
            })
            ->when($filters['action'] ?? null, fn ($query, string $action) => $query->where('action', $action))
            ->latest('id')
            ->paginate(30)
            ->withQueryString();
        $actions = AdminAuditLog::query()->distinct()->orderBy('action')->pluck('action');

        return view('admin.audit.index', compact('logs', 'actions', 'filters'));
    }

    public function updateRole(Request $request, User $user, AdminAuditLogger $auditLogger)
    {
        $data = $request->validate([
            'role' => ['required', Rule::in(['organiser', 'super_admin'])],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $updated = DB::transaction(function () use ($request, $user, $data, $auditLogger): User {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            if ($locked->is($request->user()) && $data['role'] !== 'super_admin') {
                throw ValidationException::withMessages(['role' => 'You cannot remove your own System Admin access.']);
            }
            if ($locked->isSuperAdmin() && $data['role'] === 'organiser') {
                $adminCount = User::where('role', 'super_admin')->lockForUpdate()->get(['id'])->count();
                if ($adminCount <= 1) {
                    throw ValidationException::withMessages(['role' => 'At least one System Admin must remain active.']);
                }
            }

            $before = ['role' => $locked->role];
            $locked->forceFill(['role' => $data['role']])->save();
            $auditLogger->record(
                actor: $request->user(),
                action: 'user.role_changed',
                subject: $locked,
                auditable: $locked,
                before: $before,
                after: ['role' => $locked->role],
                reason: $data['reason'],
                request: $request,
            );

            return $locked;
        }, 3);

        return back()->with('success', $updated->name.' is now '.($updated->isSuperAdmin() ? 'a System Admin' : 'an organiser').'.');
    }

}
