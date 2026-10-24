<?php

namespace App\Services;

use App\Models\{Delivery, Event, EventSettlement, Invitee, NotificationDelivery, OrganiserVerification, ParticipationPayment, User};
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class AdminReportingService
{
    public function dashboard(): array
    {
        $now = now();
        $start = $now->copy()->subDays(29)->startOfDay();
        $pendingVerification = OrganiserVerification::where('status', OrganiserVerification::STATUS_PENDING);
        $oldestSubmission = (clone $pendingVerification)->whereNotNull('submitted_at')->min('submitted_at');

        return [
            'stats' => [
                'organisers' => User::where('role', 'organiser')->count(),
                'super_admins' => User::where('role', 'super_admin')->count(),
                'events' => Event::count(),
                'participants' => Invitee::count(),
            ],
            'kpis' => [
                'pending_verifications' => (clone $pendingVerification)->count(),
                'oldest_verification_days' => $oldestSubmission ? (int) $now->diffInDays($oldestSubmission) : null,
                'upcoming_events' => Event::where('starts_at', '>=', $now)->count(),
                'upcoming_30_days' => Event::whereBetween('starts_at', [$now, $now->copy()->addDays(30)])->count(),
                'recent_confirmed_seats' => (int) Invitee::where('rsvp_status', 'accepted')
                    ->where('confirmation_status', 'confirmed')
                    ->where('responded_at', '>=', $start)
                    ->sum('attending_count'),
                'delivery_failures' => Delivery::where('status', 'failed')->count()
                    + NotificationDelivery::where('status', 'failed')->count(),
                'pending_settlements' => EventSettlement::whereIn('status', ['initiated', 'pending'])->count(),
            ],
            'collections' => $this->collectionPosition(),
            'activity' => $this->activitySeries($start, $now),
            'rsvpFunnel' => $this->rsvpFunnel(),
            'deliveryBreakdown' => $this->deliveryBreakdown(),
            'verificationDistribution' => $this->verificationDistribution(),
            'actionQueue' => $this->actionQueue(),
            'upcomingEvents' => Event::query()
                ->with(['user:id,name,email,phone'])
                ->withSum(['invitees as confirmed_seats' => fn (Builder $query) => $query
                    ->where('rsvp_status', 'accepted')
                    ->where('confirmation_status', 'confirmed')], 'attending_count')
                ->where('starts_at', '>=', $now)
                ->orderBy('starts_at')
                ->limit(6)
                ->get(['id', 'uuid', 'user_id', 'title', 'venue', 'starts_at', 'capacity']),
        ];
    }

    public function participationPaymentSummary(Builder $query): Collection
    {
        return (clone $query)
            ->withoutEagerLoads()
            ->reorder()
            ->selectRaw('currency, status, COUNT(*) as payment_count, COALESCE(SUM(amount), 0) as total_amount')
            ->groupBy('currency', 'status')
            ->orderBy('currency')
            ->orderBy('status')
            ->get();
    }

    public function settlementSummary(Builder $query): Collection
    {
        return (clone $query)
            ->withoutEagerLoads()
            ->reorder()
            ->selectRaw('currency, status, COUNT(*) as settlement_count, COALESCE(SUM(amount), 0) as total_amount')
            ->groupBy('currency', 'status')
            ->orderBy('currency')
            ->orderBy('status')
            ->get();
    }

    private function collectionPosition(): Collection
    {
        $collections = ParticipationPayment::query()
            ->where('status', 'paid')
            ->selectRaw('currency, COUNT(*) as payment_count, COALESCE(SUM(amount), 0) as collected')
            ->groupBy('currency')
            ->get()
            ->keyBy('currency');
        $settlements = EventSettlement::query()
            ->whereIn('status', ['initiated', 'pending', 'paid'])
            ->selectRaw("currency, SUM(CASE WHEN status IN ('initiated', 'pending') THEN 1 ELSE 0 END) as pending_count, COALESCE(SUM(amount), 0) as reserved")
            ->groupBy('currency')
            ->get()
            ->keyBy('currency');

        return $collections->keys()->merge($settlements->keys())->unique()->sort()->values()->map(function (string $currency) use ($collections, $settlements) {
            $collected = (float) ($collections->get($currency)?->collected ?? 0);
            $reserved = (float) ($settlements->get($currency)?->reserved ?? 0);

            return [
                'currency' => $currency,
                'payment_count' => (int) ($collections->get($currency)?->payment_count ?? 0),
                'collected' => $collected,
                'reserved' => $reserved,
                'available' => max(0, $collected - $reserved),
                'pending_settlement_count' => (int) ($settlements->get($currency)?->pending_count ?? 0),
            ];
        });
    }

    private function activitySeries(CarbonInterface $start, CarbonInterface $end): array
    {
        $eventCounts = $this->dailyAggregate(Event::query(), 'created_at', $start, $end, 'COUNT(*)');
        $seatCounts = $this->dailyAggregate(
            Invitee::query()->where('rsvp_status', 'accepted')->where('confirmation_status', 'confirmed'),
            'responded_at',
            $start,
            $end,
            'COALESCE(SUM(attending_count), 0)',
        );
        $days = [];

        for ($day = $start->copy(); $day->lte($end); $day->addDay()) {
            $key = $day->toDateString();
            $days[] = [
                'date' => $key,
                'label' => $day->format('d M'),
                'events' => (int) ($eventCounts[$key] ?? 0),
                'confirmed_seats' => (int) ($seatCounts[$key] ?? 0),
            ];
        }

        return $days;
    }

    private function dailyAggregate(Builder $query, string $column, CarbonInterface $start, CarbonInterface $end, string $aggregate): Collection
    {
        return $query
            ->whereNotNull($column)
            ->whereBetween($column, [$start, $end])
            ->selectRaw("DATE({$column}) as activity_date, {$aggregate} as aggregate_value")
            ->groupByRaw("DATE({$column})")
            ->pluck('aggregate_value', 'activity_date');
    }

    private function rsvpFunnel(): array
    {
        return [
            ['label' => 'Invited / registered', 'value' => Invitee::count()],
            ['label' => 'Responded', 'value' => Invitee::whereIn('rsvp_status', ['accepted', 'declined'])->count()],
            ['label' => 'Confirmed', 'value' => Invitee::where('rsvp_status', 'accepted')->where('confirmation_status', 'confirmed')->count()],
            ['label' => 'Payment confirmed', 'value' => Invitee::where('participation_payment_status', 'paid')->count()],
            ['label' => 'Checked in', 'value' => Invitee::where('attended_count', '>', 0)->count()],
        ];
    }

    private function deliveryBreakdown(): Collection
    {
        $invitations = Delivery::query()
            ->selectRaw('channel, status, COUNT(*) as delivery_count')
            ->groupBy('channel', 'status')
            ->get();
        $notifications = NotificationDelivery::query()
            ->selectRaw('channel, status, COUNT(*) as delivery_count')
            ->groupBy('channel', 'status')
            ->get();

        return $invitations->concat($notifications)
            ->groupBy(fn ($row) => strtolower($row->channel).'|'.strtolower($row->status))
            ->map(function (Collection $rows) {
                $first = $rows->first();

                return [
                    'channel' => strtolower($first->channel),
                    'status' => strtolower($first->status),
                    'count' => (int) $rows->sum('delivery_count'),
                ];
            })
            ->sortBy(fn (array $row) => $row['channel'].'|'.$row['status'])
            ->values();
    }

    private function verificationDistribution(): Collection
    {
        $organisers = User::where('role', 'organiser')->count();
        $recorded = OrganiserVerification::query()
            ->whereHas('user', fn (Builder $query) => $query->where('role', 'organiser'))
            ->selectRaw('status, COUNT(*) as verification_count')
            ->groupBy('status')
            ->pluck('verification_count', 'status');
        $expiredApprovals = OrganiserVerification::query()
            ->where('status', OrganiserVerification::STATUS_APPROVED)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->whereHas('user', fn (Builder $query) => $query->where('role', 'organiser'))
            ->count();

        if ($expiredApprovals > 0) {
            $recorded[OrganiserVerification::STATUS_APPROVED] = max(0, (int) ($recorded[OrganiserVerification::STATUS_APPROVED] ?? 0) - $expiredApprovals);
            $recorded[OrganiserVerification::STATUS_EXPIRED] = (int) ($recorded[OrganiserVerification::STATUS_EXPIRED] ?? 0) + $expiredApprovals;
        }
        $missing = max(0, $organisers - (int) $recorded->sum());
        $recorded[OrganiserVerification::STATUS_UNVERIFIED] = (int) ($recorded[OrganiserVerification::STATUS_UNVERIFIED] ?? 0) + $missing;

        return collect(OrganiserVerification::STATUSES)->map(fn (string $status) => [
            'status' => $status,
            'count' => (int) ($recorded[$status] ?? 0),
        ]);
    }

    private function actionQueue(): Collection
    {
        $verificationItems = OrganiserVerification::query()
            ->with('user:id,name,email,phone')
            ->where('status', OrganiserVerification::STATUS_PENDING)
            ->orderByRaw('CASE WHEN submitted_at IS NULL THEN 1 ELSE 0 END')
            ->orderBy('submitted_at')
            ->limit(4)
            ->get(['id', 'user_id', 'organiser_type', 'submitted_at'])
            ->map(fn (OrganiserVerification $verification) => [
                'type' => 'verification',
                'priority' => 'review',
                'title' => $verification->user->name,
                'detail' => ucfirst($verification->organiser_type).' verification awaiting review',
                'age' => $verification->submitted_at?->diffForHumans() ?? 'Submission time unavailable',
                'url' => route('admin.verifications.show', $verification->user),
            ]);
        $settlementItems = EventSettlement::query()
            ->with('event:id,uuid,title')
            ->where(function (Builder $query) {
                $query->whereIn('status', ['failed', 'reversed'])
                    ->orWhere(fn (Builder $pending) => $pending->whereIn('status', ['initiated', 'pending'])->where('created_at', '<=', now()->subDay()));
            })
            ->orderByRaw("CASE WHEN status IN ('failed', 'reversed') THEN 0 ELSE 1 END")
            ->orderBy('created_at')
            ->limit(4)
            ->get(['id', 'event_id', 'amount', 'currency', 'status', 'created_at'])
            ->map(fn (EventSettlement $settlement) => [
                'type' => 'settlement',
                'priority' => in_array($settlement->status, ['failed', 'reversed'], true) ? 'urgent' : 'review',
                'title' => $settlement->event->title,
                'detail' => $settlement->currency.' '.number_format((float) $settlement->amount, 2).' settlement '.str_replace('_', ' ', $settlement->status),
                'age' => $settlement->created_at->diffForHumans(),
                'url' => route('admin.settlements', ['status' => $settlement->status]),
            ]);
        $deliveryItems = Delivery::query()
            ->with('invitee:id,event_id,name')
            ->where('status', 'failed')
            ->orderByDesc('updated_at')
            ->limit(3)
            ->get(['id', 'invitee_id', 'channel', 'status', 'updated_at'])
            ->map(fn (Delivery $delivery) => [
                'type' => 'delivery',
                'priority' => 'review',
                'title' => $delivery->invitee->name,
                'detail' => strtoupper($delivery->channel).' invitation delivery failed',
                'age' => $delivery->updated_at->diffForHumans(),
                'url' => route('admin.deliveries', ['status' => 'failed']),
            ]);
        $notificationItems = NotificationDelivery::query()
            ->with('invitee.event:id,uuid')
            ->where('status', 'failed')
            ->orderByDesc('updated_at')
            ->limit(3)
            ->get(['id', 'invitee_id', 'type', 'channel', 'status', 'updated_at'])
            ->map(fn (NotificationDelivery $delivery) => [
                'type' => 'delivery',
                'priority' => 'review',
                'title' => $delivery->invitee->name,
                'detail' => strtoupper($delivery->channel).' '.str_replace('_', ' ', $delivery->type).' notification failed',
                'age' => $delivery->updated_at->diffForHumans(),
                'url' => route('events.participants', $delivery->invitee->event),
            ]);

        return $verificationItems->concat($settlementItems)->concat($deliveryItems)->concat($notificationItems)->take(10)->values();
    }
}
