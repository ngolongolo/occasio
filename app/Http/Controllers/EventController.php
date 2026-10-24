<?php

namespace App\Http\Controllers;

use App\Jobs\SendInvitation;
use App\Models\{Delivery, Event, EventPackage, Invitee, ParticipationPayment, Pledge, PledgePayment, User};
use App\Services\GuestImporter;
use App\Services\InvitationTemplate;
use App\Services\NotificationDispatcher;
use App\Services\EventPricingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EventController
{
    private const MAX_PARTICIPATION_PAYMENT = 999999999999.99;

    private function own(Event $event): void
    {
        if ((int) $event->user_id === (int) auth()->id()) return;

        abort_unless(auth()->user()->isSuperAdmin(), 403);
        abort_unless(request()->isMethodSafe(), 403, 'System Admin oversight is read-only. Use an explicit admin control for changes.');
    }

    public function index()
    {
        if (auth()->user()->isSuperAdmin()) return redirect()->route('admin.index');
        $events = auth()->user()->events()->withCount([
            'invitees',
            'invitees as accepted_count' => fn ($query) => $query->where('rsvp_status', 'accepted'),
            'invitees as pending_count' => fn ($query) => $query->where('rsvp_status', 'pending'),
        ])->orderBy('starts_at')->get();

        return view('events.index', [
            'events' => $events,
            'dashboardStats' => [
                'events' => $events->count(),
                'upcoming' => $events->filter(fn ($event) => ! $event->responsesClosed())->count(),
                'invitees' => $events->sum('invitees_count'),
                'confirmed' => $events->sum('accepted_count'),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $request->merge([
            'event_type' => $request->input('event_type', 'hangout'),
            'visibility' => $request->input('visibility', 'invite_only'),
            'participation_payment_timing' => $request->input('participation_payment_timing', 'free'),
            'pricing_mode' => $request->input('pricing_mode', 'single'),
        ]);
        $data = $request->validate([
            'event_type' => 'sometimes|in:event,hangout',
            'title' => 'required|string|max:180', 'host' => 'required|string|max:180',
            'description' => 'required|string|max:5000', 'starts_at' => 'required|date|after:now',
            'ends_at' => 'nullable|date|after:starts_at',
            'rsvp_deadline' => 'required|date|before_or_equal:starts_at', 'venue' => 'required|string|max:255',
            'venue_latitude' => 'nullable|numeric|between:-90,90|required_with:venue_longitude,venue_source',
            'venue_longitude' => 'nullable|numeric|between:-180,180|required_with:venue_latitude,venue_source',
            'venue_source' => 'nullable|in:nominatim|required_with:venue_latitude,venue_longitude',
            'dress_code' => 'nullable|string|max:180', 'contact' => 'nullable|string|max:100',
            'capacity' => 'nullable|integer|min:1|max:100000', 'visibility' => 'sometimes|in:invite_only,link',
            'plus_ones_allowed' => 'nullable|boolean', 'what_to_bring' => 'nullable|string|max:2000',
            'max_attendees_per_registration' => 'nullable|integer|min:1|max:10',
            'participation_payment_timing' => 'sometimes|in:free,pay_now,pay_at_event',
            'participation_collection_method' => 'nullable|in:manual,bluetick_pay',
            'pricing_mode' => 'sometimes|in:single,packages',
            'participation_price' => 'nullable|numeric|min:0.01|max:999999999999.99',
            'participation_currency' => 'nullable|required_unless:participation_payment_timing,free|string|size:3',
            'packages' => 'nullable|array|max:30',
            'packages.*' => 'array',
            'packages.*.id' => 'nullable|integer|distinct',
            'packages.*.name' => 'nullable|string|max:100',
            'packages.*.description' => 'nullable|string|max:500',
            'packages.*.price' => 'nullable|numeric|min:0.01|max:999999999999.99',
            'packages.*.capacity' => 'nullable|integer|min:1|max:100000',
            'packages.*.max_per_registration' => 'nullable|integer|min:1|max:10',
            'packages.*.is_active' => 'nullable|boolean',
            'participation_payment_details' => 'nullable|string|max:5000',
            'participation_payment_url' => 'nullable|url:http,https|max:2048',
            'cancellation_policy' => 'nullable|string|max:2000',
            'payout_payment_type' => 'nullable|in:MixxByYas,Mpesa,AirtelMoney,Ecobank',
            'payout_number' => 'nullable|string|max:50',
            'invitation_card' => ['nullable', 'image', 'mimes:png,jpg,jpeg', 'max:2048'],
        ]);
        $this->normaliseVenueSelection($request, $data);
        $data['event_type'] ??= 'hangout';
        $data['visibility'] ??= 'invite_only';
        $data['participation_payment_timing'] ??= 'free';
        $data['participation_collection_method'] ??= 'bluetick_pay';
        if ($data['participation_payment_timing'] !== 'pay_now') $data['participation_collection_method'] = 'manual';
        $data['pricing_mode'] ??= 'single';
        $data['participation_currency'] = strtoupper($data['participation_currency'] ?? 'TZS');
        $data['plus_ones_allowed'] = $request->boolean('plus_ones_allowed');
        $data['max_attendees_per_registration'] = $data['plus_ones_allowed'] ? (int) ($data['max_attendees_per_registration'] ?? 2) : 1;
        $packages = [];
        if ($data['participation_payment_timing'] !== 'free' && $data['pricing_mode'] === 'packages') {
            $packages = $this->validatedPackages(
                $data['packages'] ?? [],
                $data['participation_currency'],
                $data['participation_payment_timing'] === 'pay_now' && $data['participation_collection_method'] === 'bluetick_pay',
                null,
                $data['max_attendees_per_registration'],
            );
            $data['participation_price'] = null;
        } elseif ($data['participation_payment_timing'] !== 'free' && empty($data['participation_price'])) {
            throw ValidationException::withMessages(['participation_price' => 'Enter the price per person, or choose multiple packages.']);
        }
        $this->validatePaidEventRequirements($request->user(), $data);
        if ($data['participation_payment_timing'] === 'pay_now' && $data['participation_collection_method'] === 'bluetick_pay') {
            if (strtoupper((string) ($data['participation_currency'] ?? 'TZS')) !== 'TZS') throw ValidationException::withMessages(['participation_currency' => 'Snippe currently supports TZS payments only.']);
            if ($data['pricing_mode'] === 'single') {
                $this->validateBluetickUnitPrice(
                    $data['participation_price'] ?? 0,
                    $data['max_attendees_per_registration'],
                    'participation_price',
                );
            }
        }
        if ($data['participation_payment_timing'] === 'pay_now' && $data['participation_collection_method'] === 'manual' && empty(trim((string) ($data['participation_payment_details'] ?? '')))) throw ValidationException::withMessages(['participation_payment_details' => 'Add the payment numbers or account instructions for manual collection.']);
        if ($data['participation_payment_timing'] === 'pay_now' && $data['participation_collection_method'] === 'bluetick_pay' && (empty($data['payout_payment_type']) || empty(trim((string) ($data['payout_number'] ?? ''))))) throw ValidationException::withMessages(['payout_number' => 'Add the account where Bluetick Pay collections will be settled.']);
        if ($data['participation_payment_timing'] === 'free') {
            $data['participation_price'] = null;
            $data['pricing_mode'] = 'single';
        }
        $invitationCard = $request->file('invitation_card');
        unset($data['packages'], $data['invitation_card']);
        $storedCardPath = null;
        try {
            $event = DB::transaction(function () use ($data, $packages, $invitationCard, &$storedCardPath) {
                $event = auth()->user()->events()->create($data);
                $this->syncPackages($event, $packages, $data['participation_currency']);
                if ($invitationCard) {
                    $storedCardPath = $invitationCard->store("events/{$event->id}/cards", 'local');
                    $event->update([
                        'invitation_card_path' => $storedCardPath,
                        'invitation_card_preview_path' => $storedCardPath,
                        'invitation_card_name' => $invitationCard->getClientOriginalName(),
                        'invitation_card_mime' => $invitationCard->getMimeType(),
                    ]);
                }

                return $event;
            });
        } catch (\Throwable $exception) {
            if ($storedCardPath) Storage::disk('local')->delete($storedCardPath);
            throw $exception;
        }
        return redirect()->route('events.show', $event);
    }

    public function show(Request $request, Event $event)
    {
        return $this->workspace($request, $event, 'overview');
    }

    public function participants(Request $request, Event $event) { return $this->workspace($request, $event, 'participants'); }
    public function invitations(Request $request, Event $event)
    {
        $this->own($event);

        return redirect()->route('events.participants', $event);
    }

    public function attendance(Request $request, Event $event)
    {
        $this->own($event);
        abort_unless($event->participation_enabled, 404);

        return redirect()->route('events.participants', $event);
    }
    public function pledges(Request $request, Event $event) { abort_unless($event->pledge_enabled, 404); return $this->workspace($request, $event, 'pledges'); }
    public function payments(Request $request, Event $event)
    {
        $this->own($event);
        abort_if($event->participation_payment_timing === 'free', 404);

        return $this->workspace($request, $event, 'payments');
    }

    private function workspace(Request $request, Event $event, string $workspaceTab)
    {
        $this->own($event);
        $adminReadOnly = auth()->user()->isSuperAdmin() && (int) $event->user_id !== (int) auth()->id();
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'rsvp' => ['nullable', 'in:pending,accepted,declined'],
            'delivery' => ['nullable', 'in:not_sent,queued,previewed,submitted,failed'],
            'per_page' => ['nullable', 'integer', 'in:10,25,50,100'],
            'payment_q' => ['nullable', 'string', 'max:100'],
            'payment_status' => ['nullable', 'in:pending,paid,failed,superseded'],
        ]);
        $query = $event->invitees()->with(['deliveries', 'pledge.payments', 'notificationDeliveries'])->orderBy('name');
        if (! empty($filters['q'])) {
            $search = trim($filters['q']);
            $query->where(function ($query) use ($search) {
                $query->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }
        if (! empty($filters['rsvp'])) $query->where('rsvp_status', $filters['rsvp']);
        if (($filters['delivery'] ?? null) === 'not_sent') {
            $query->doesntHave('deliveries');
        } elseif (! empty($filters['delivery'])) {
            $query->whereHas('deliveries', fn ($delivery) => $delivery->where('status', $filters['delivery']));
        }
        $paymentSummary = null;
        $paymentOperations = null;
        $isBluetickParticipation = $event->participation_payment_timing === 'pay_now'
            && $event->participation_collection_method === 'bluetick_pay';
        if (in_array($workspaceTab, ['overview', 'payments'], true) && $event->participation_payment_timing !== 'free') {
            $isBluetick = $isBluetickParticipation;
            $collected = $isBluetick ? (float) $event->participationPayments()->where('status', 'paid')->sum('amount')
                : $this->manualParticipationTotal($event, 'paid');
            $reserved = (float) $event->settlements()->whereIn('status', ['initiated', 'pending', 'paid'])->sum('amount');
            $paymentSummary = [
                'collected' => $collected,
                'available' => max(0, $collected - $reserved),
                'pending' => $isBluetick ? $event->participationPayments()->whereIn('status', ['initiated', 'pending'])->count() : $event->invitees()->where('participation_payment_status', 'payment_reported')->count(),
                'failed' => $isBluetick ? $event->participationPayments()->whereIn('status', ['failed', 'request_failed'])->count() : $event->invitees()->where('participation_payment_status', 'rejected')->count(),
            ];
        }

        if ($workspaceTab === 'payments' && $paymentSummary !== null) {
            $isBluetick = $isBluetickParticipation;
            $paymentSearch = trim((string) ($filters['payment_q'] ?? ''));
            $paymentStatus = (string) ($filters['payment_status'] ?? '');

            if ($isBluetick) {
                $paymentQuery = ParticipationPayment::query()->whereIn('invitee_id', $event->invitees()->select('id'));
                if ($paymentSearch !== '') {
                    $paymentQuery->where(function ($query) use ($paymentSearch) {
                        $query->where('reference', 'like', "%{$paymentSearch}%")
                            ->orWhere('provider_payment_reference', 'like', "%{$paymentSearch}%")
                            ->orWhere('msisdn', 'like', "%{$paymentSearch}%")
                            ->orWhereHas('invitee', fn ($invitee) => $invitee->where('name', 'like', "%{$paymentSearch}%")
                                ->orWhere('email', 'like', "%{$paymentSearch}%")
                                ->orWhere('phone', 'like', "%{$paymentSearch}%")
                                ->orWhere('package_name_snapshot', 'like', "%{$paymentSearch}%"));
                    });
                }
                if ($paymentStatus === 'pending') $paymentQuery->whereIn('status', ['initiated', 'pending']);
                elseif ($paymentStatus === 'failed') $paymentQuery->whereIn('status', ['failed', 'request_failed']);
                elseif ($paymentStatus !== '') $paymentQuery->where('status', $paymentStatus);

                $paymentMatchCount = (clone $paymentQuery)->count();
                $payments = $paymentQuery
                    ->select(['id', 'invitee_id', 'reference', 'amount', 'currency', 'status', 'provider_payment_reference', 'requested_at', 'paid_at', 'failed_at', 'created_at'])
                    ->with('invitee:id,name,package_name_snapshot,package_unit_price_snapshot,package_currency_snapshot,attending_count')->orderByDesc('id')->limit(50)->get();
                $manualPayments = collect();
            } else {
                $manualQuery = $event->invitees()->where('rsvp_status', 'accepted')->where('participation_payment_status', '!=', 'not_required');
                if ($paymentSearch !== '') {
                    $manualQuery->where(function ($query) use ($paymentSearch) {
                        $query->where('name', 'like', "%{$paymentSearch}%")
                            ->orWhere('email', 'like', "%{$paymentSearch}%")
                            ->orWhere('phone', 'like', "%{$paymentSearch}%")
                            ->orWhere('package_name_snapshot', 'like', "%{$paymentSearch}%")
                            ->orWhere('participation_payment_reference', 'like', "%{$paymentSearch}%");
                    });
                }
                if ($paymentStatus === 'pending') $manualQuery->where('participation_payment_status', 'payment_reported');
                elseif ($paymentStatus === 'failed') $manualQuery->whereIn('participation_payment_status', ['failed', 'rejected']);
                elseif ($paymentStatus === 'superseded') $manualQuery->whereRaw('1 = 0');
                elseif ($paymentStatus !== '') $manualQuery->where('participation_payment_status', $paymentStatus);

                $paymentMatchCount = (clone $manualQuery)->count();
                $manualPayments = $manualQuery->select(['id', 'event_id', 'name', 'attending_count', 'package_name_snapshot', 'package_unit_price_snapshot', 'package_currency_snapshot', 'participation_payment_status', 'participation_payment_reference', 'responded_at'])
                    ->orderByDesc('id')->limit(50)->get();
                $payments = collect();
            }

            $paymentOperations = array_merge($paymentSummary, [
                'payments' => $payments, 'manualPayments' => $manualPayments,
                'paymentSearch' => $paymentSearch, 'paymentStatus' => $paymentStatus, 'paymentMatchCount' => $paymentMatchCount,
                'settlements' => $event->settlements()->select(['id', 'event_id', 'amount', 'currency', 'payment_type', 'payment_number', 'account_name', 'status', 'created_at'])->orderByDesc('id')->limit(10)->get(),
            ]);
        }
        return view('events.show', [
            'event' => $event,
            'adminReadOnly' => $adminReadOnly,
            'invitees' => $query->paginate((int) ($filters['per_page'] ?? 25))->withQueryString(),
            'filters' => $filters,
            'workspaceTab' => $workspaceTab,
            'paymentSummary' => $paymentSummary,
            'paymentOperations' => $paymentOperations,
            'isBluetickParticipation' => $isBluetickParticipation,
            'stats' => [
                'total' => $event->invitees()->count(),
                'accepted' => $event->invitees()->where('rsvp_status', 'accepted')->count(),
                'declined' => $event->invitees()->where('rsvp_status', 'declined')->count(),
                'seats' => $event->invitees()->sum('attending_count'),
                'pledged' => $event->pledges()->sum('amount'),
                'confirmed_pledges' => $event->pledges()->where('status', 'paid')->sum('amount'),
                'attended' => $event->invitees()->where('attendance_status', 'attended')->count(),
                'attended_seats' => $event->invitees()->sum('attended_count'),
                'collected' => PledgePayment::whereHas('pledge.invitee', fn ($query) => $query->where('event_id', $event->id))->where('status', 'recorded')->sum('amount'),
                'partially_paid' => $event->pledges()->where('status', 'partially_paid')->count(),
            ],
        ]);
    }

    public function design(Event $event)
    {
        $this->own($event);
        return view('events.design', [
            'event' => $event,
            'defaultSms' => InvitationTemplate::DEFAULT_SMS,
            'adminReadOnly' => auth()->user()->isSuperAdmin() && (int) $event->user_id !== (int) auth()->id(),
        ]);
    }

    public function updateDesign(Request $request, Event $event)
    {
        $this->own($event);
        $data = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:180'],
            'host' => ['sometimes', 'required', 'string', 'max:180'],
            'description' => ['sometimes', 'required', 'string', 'max:5000'],
            'starts_at' => ['sometimes', 'required', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'rsvp_deadline' => ['sometimes', 'required', 'date', 'before_or_equal:starts_at'],
            'venue' => ['sometimes', 'required', 'string', 'max:255'],
            'venue_latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:venue_longitude,venue_source'],
            'venue_longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:venue_latitude,venue_source'],
            'venue_source' => ['nullable', 'in:nominatim', 'required_with:venue_latitude,venue_longitude'],
            'dress_code' => ['nullable', 'string', 'max:180'],
            'contact' => ['nullable', 'string', 'max:100'],
            'event_type' => ['sometimes', 'required', 'in:event,hangout'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'visibility' => ['sometimes', 'required', 'in:invite_only,link'],
            'plus_ones_allowed' => ['nullable', 'boolean'],
            'max_attendees_per_registration' => ['nullable', 'integer', 'min:1', 'max:10'],
            'what_to_bring' => ['nullable', 'string', 'max:2000'],
            'primary_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'background_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'text_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'logo' => ['nullable', 'file', 'mimes:png,jpg,jpeg', 'max:2048'],
            'invitation_card' => ['nullable', 'image', 'mimes:png,jpg,jpeg', 'max:2048'],
            'sms_template' => ['required', 'string', 'max:1000'],
            'participation_enabled' => ['nullable', 'boolean'],
            'participation_payment_timing' => ['nullable', 'in:free,pay_now,pay_at_event'],
            'participation_collection_method' => ['nullable', 'in:manual,bluetick_pay'],
            'pricing_mode' => ['nullable', 'in:single,packages'],
            'participation_price' => ['nullable', 'numeric', 'min:0.01', 'max:999999999999.99'],
            'participation_currency' => ['nullable', 'string', 'size:3'],
            'packages' => ['nullable', 'array', 'max:30'],
            'packages.*' => ['array'],
            'packages.*.id' => ['nullable', 'integer', 'distinct'],
            'packages.*.name' => ['nullable', 'string', 'max:100'],
            'packages.*.description' => ['nullable', 'string', 'max:500'],
            'packages.*.price' => ['nullable', 'numeric', 'min:0.01', 'max:999999999999.99'],
            'packages.*.capacity' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'packages.*.max_per_registration' => ['nullable', 'integer', 'min:1', 'max:10'],
            'packages.*.is_active' => ['nullable', 'boolean'],
            'participation_payment_details' => ['nullable', 'string', 'max:5000'],
            'participation_payment_url' => ['nullable', 'url:http,https', 'max:2048'],
            'cancellation_policy' => ['nullable', 'string', 'max:2000'],
            'payout_payment_type' => ['nullable', 'in:MixxByYas,Mpesa,AirtelMoney,Ecobank'],
            'payout_number' => ['nullable', 'string', 'max:50'],
            'rsvp_form_preset' => ['nullable', 'in:simple,social,professional,custom'],
            'rsvp_confirmation_mode' => ['nullable', 'in:automatic,organizer_approval,after_payment'],
            'rsvp_fields' => ['nullable', 'array'], 'rsvp_fields.*.enabled' => ['nullable','boolean'], 'rsvp_fields.*.required' => ['nullable','boolean'],
            'pledge_enabled' => ['nullable', 'boolean'],
            'pledge_currency' => ['nullable', 'string', 'size:3', 'required_if:pledge_enabled,1'],
            'pledge_message' => ['nullable', 'string', 'max:2000'],
            'payment_details' => ['nullable', 'string', 'max:5000', 'required_if:pledge_enabled,1'],
            'attendance_thanks_enabled' => ['nullable', 'boolean'],
            'attendance_thanks_mode' => ['nullable', 'in:automatic,manual'],
            'attendance_thanks_email' => ['nullable', 'boolean'],
            'attendance_thanks_sms' => ['nullable', 'boolean'],
            'attendance_email_subject' => ['nullable', 'string', 'max:180'],
            'attendance_email_message' => ['nullable', 'string', 'max:5000'],
            'attendance_sms_message' => ['nullable', 'string', 'max:1000'],
            'pledge_thanks_enabled' => ['nullable', 'boolean'],
            'pledge_thanks_mode' => ['nullable', 'in:automatic,manual'],
            'pledge_thanks_email' => ['nullable', 'boolean'],
            'pledge_thanks_sms' => ['nullable', 'boolean'],
            'pledge_email_subject' => ['nullable', 'string', 'max:180'],
            'pledge_email_message' => ['nullable', 'string', 'max:5000'],
            'pledge_sms_message' => ['nullable', 'string', 'max:1000'],
            'payment_receipt_enabled' => ['nullable', 'boolean'],
            'payment_receipt_mode' => ['nullable', 'in:automatic,manual'],
            'payment_receipt_email' => ['nullable', 'boolean'],
            'payment_receipt_sms' => ['nullable', 'boolean'],
            'payment_receipt_email_subject' => ['nullable', 'string', 'max:180'],
            'payment_receipt_email_message' => ['nullable', 'string', 'max:5000'],
            'payment_receipt_sms_message' => ['nullable', 'string', 'max:1000'],
            'remove_logo' => ['nullable', 'boolean'],
            'remove_invitation_card' => ['nullable', 'boolean'],
        ]);
        $this->normaliseVenueSelection($request, $data, $event);

        $data['participation_enabled'] = $request->has('response_options_present') ? $request->boolean('participation_enabled') : $event->participation_enabled;
        if($request->has('rsvp_configuration_present')){
            $allowed=['email','phone','whatsapp','dietary','organization','job_title','accessibility','notes'];$config=[];
            foreach($allowed as $field)$config[$field]=['enabled'=>$request->boolean("rsvp_fields.$field.enabled"),'required'=>$request->boolean("rsvp_fields.$field.required")];
            if(!$config['email']['enabled']&&!$config['phone']['enabled'])throw ValidationException::withMessages(['rsvp_fields.email.enabled'=>'Enable email, phone, or both so registrations can be identified.']);
            $data['rsvp_field_config']=$config;
        }
        unset($data['rsvp_fields']);
        $data['plus_ones_allowed'] = $request->has('event_details_present') ? $request->boolean('plus_ones_allowed') : $event->plus_ones_allowed;
        $data['max_attendees_per_registration'] = $request->has('event_details_present') ? ($data['plus_ones_allowed'] ? (int) ($data['max_attendees_per_registration'] ?? 2) : 1) : $event->max_attendees_per_registration;
        $data['participation_payment_timing'] ??= $event->participation_payment_timing ?: 'free';
        $data['participation_collection_method'] ??= $event->participation_collection_method ?: 'bluetick_pay';
        if ($data['participation_payment_timing'] !== 'pay_now') $data['participation_collection_method'] = 'manual';
        $data['pricing_mode'] ??= $event->pricing_mode ?: 'single';
        $data['participation_currency'] = strtoupper($data['participation_currency'] ?? $event->participation_currency ?? 'TZS');
        $packages = [];
        if ($data['participation_payment_timing'] !== 'free' && $data['pricing_mode'] === 'packages') {
            $packageInput = array_key_exists('packages', $data) ? $data['packages'] : $event->packages()->get()->map(fn ($package) => [
                'id' => $package->id,
                'name' => $package->name,
                'description' => $package->description,
                'price' => $package->price,
                'capacity' => $package->capacity,
                'max_per_registration' => $package->max_per_registration,
                'is_active' => $package->is_active,
            ])->all();
            $packages = $this->validatedPackages(
                $packageInput,
                $data['participation_currency'],
                $data['participation_payment_timing'] === 'pay_now' && $data['participation_collection_method'] === 'bluetick_pay',
                $event,
                $data['max_attendees_per_registration'],
            );
            $data['participation_price'] = null;
        } elseif ($data['participation_payment_timing'] !== 'free' && empty($data['participation_price'] ?? $event->participation_price)) {
            throw ValidationException::withMessages(['participation_price' => 'Enter the price per person, or choose multiple packages.']);
        }
        if ($this->paymentConfigurationChanged($event, $data, $packages)) {
            $this->validatePaidEventRequirements($request->user(), array_merge([
                'contact' => $event->contact,
                'cancellation_policy' => $event->cancellation_policy,
                'visibility' => $event->visibility,
            ], $data));
        }
        if ($data['participation_payment_timing'] === 'pay_now' && $data['participation_collection_method'] === 'bluetick_pay') {
            if (strtoupper((string) ($data['participation_currency'] ?? $event->participation_currency ?? 'TZS')) !== 'TZS') throw ValidationException::withMessages(['participation_currency' => 'Snippe currently supports TZS payments only.']);
            if ($data['pricing_mode'] === 'single') {
                $this->validateBluetickUnitPrice(
                    $data['participation_price'] ?? $event->participation_price ?? 0,
                    $data['max_attendees_per_registration'],
                    'participation_price',
                );
            }
        }
        if ($data['participation_payment_timing'] !== 'free') {
            if ($data['participation_payment_timing'] === 'pay_now' && $data['participation_collection_method'] === 'manual' && empty(trim((string) ($data['participation_payment_details'] ?? '')))) throw ValidationException::withMessages(['participation_payment_details' => 'Add payment numbers or account instructions for guests.']);
            if ($data['participation_payment_timing'] === 'pay_now' && $data['participation_collection_method'] === 'bluetick_pay' && (empty($data['payout_payment_type']) || empty(trim((string) ($data['payout_number'] ?? ''))))) throw ValidationException::withMessages(['payout_number' => 'Add the account where Bluetick Pay collections will be settled.']);
        } else {
            $data['participation_price'] = null;
            $data['pricing_mode'] = 'single';
        }
        $data['pledge_enabled'] = $request->has('response_options_present') ? $request->boolean('pledge_enabled') : $event->pledge_enabled;
        if (! $data['participation_enabled'] && ! $data['pledge_enabled']) {
            throw ValidationException::withMessages(['participation_enabled' => 'Enable participation, pledges, or both.']);
        }
        $data['pledge_currency'] = strtoupper($data['pledge_currency'] ?? $event->pledge_currency ?? 'TZS');
        $notificationBooleans = ['attendance_thanks_enabled', 'attendance_thanks_email', 'attendance_thanks_sms', 'pledge_thanks_enabled', 'pledge_thanks_email', 'pledge_thanks_sms', 'payment_receipt_enabled', 'payment_receipt_email', 'payment_receipt_sms'];
        if ($request->has('notification_options_present')) {
            foreach ($notificationBooleans as $field) $data[$field] = $request->boolean($field);
            foreach (['attendance', 'pledge'] as $prefix) {
                if ($data[$prefix.'_thanks_enabled'] && ! $data[$prefix.'_thanks_email'] && ! $data[$prefix.'_thanks_sms']) {
                    throw ValidationException::withMessages([$prefix.'_thanks_email' => 'Select email, SMS, or both for enabled notifications.']);
                }
            }
            if ($data['payment_receipt_enabled'] && ! $data['payment_receipt_email'] && ! $data['payment_receipt_sms']) throw ValidationException::withMessages(['payment_receipt_email' => 'Select email, SMS, or both for enabled payment receipts.']);
        } else {
            foreach ($notificationBooleans as $field) $data[$field] = $event->{$field};
            $data['attendance_thanks_mode'] ??= $event->attendance_thanks_mode;
            $data['pledge_thanks_mode'] ??= $event->pledge_thanks_mode;
            $data['payment_receipt_mode'] ??= $event->payment_receipt_mode;
        }

        if ($request->boolean('remove_logo') && $event->logo_path) {
            Storage::disk('local')->delete($event->logo_path);
            $data['logo_path'] = null;
        }
        if ($request->hasFile('logo')) {
            if ($event->logo_path) Storage::disk('local')->delete($event->logo_path);
            $data['logo_path'] = $request->file('logo')->store("events/{$event->id}/branding", 'local');
        }

        if ($request->boolean('remove_invitation_card') && $event->invitation_card_path) {
            Storage::disk('local')->delete($event->invitation_card_path);
            if ($event->invitation_card_preview_path && $event->invitation_card_preview_path !== $event->invitation_card_path) Storage::disk('local')->delete($event->invitation_card_preview_path);
            $data['invitation_card_path'] = $data['invitation_card_preview_path'] = $data['invitation_card_name'] = $data['invitation_card_mime'] = null;
        }
        if ($request->hasFile('invitation_card')) {
            if ($event->invitation_card_path) Storage::disk('local')->delete($event->invitation_card_path);
            if ($event->invitation_card_preview_path && $event->invitation_card_preview_path !== $event->invitation_card_path) Storage::disk('local')->delete($event->invitation_card_preview_path);
            $card = $request->file('invitation_card');
            $data['invitation_card_path'] = $card->store("events/{$event->id}/cards", 'local');
            $data['invitation_card_name'] = $card->getClientOriginalName();
            $data['invitation_card_mime'] = $card->getMimeType();
            $data['invitation_card_preview_path'] = $data['invitation_card_path'];
        }

        unset($data['logo'], $data['invitation_card'], $data['remove_logo'], $data['remove_invitation_card'], $data['packages']);
        DB::transaction(function () use ($event, $data, $packages) {
            $event->update($data);
            $this->syncPackages($event, $data['pricing_mode'] === 'packages' ? $packages : [], $data['participation_currency']);
        });
        return redirect()->route('events.design', $event)->with('success', 'Event settings saved.');
    }

    public function downloadCard(Event $event)
    {
        $this->own($event);
        abort_unless($event->invitation_card_path && Storage::disk('local')->exists($event->invitation_card_path), 404);
        return Storage::disk('local')->response($event->invitation_card_path, $event->invitation_card_name, [
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }

    public function import(Request $request, Event $event, GuestImporter $importer)
    {
        $this->own($event);
        $request->validate(['file' => 'required|file|mimes:xlsx,xls,csv,txt|max:5120']);
        $report = $importer->import($request->file('file'), $event);
        return back()->with('success', "Imported {$report['imported']} guests; skipped {$report['duplicates']} duplicates.")->with('import_errors', $report['errors']);
    }

    public function send(Request $request, Event $event)
    {
        $this->own($event);
        $data = $request->validate([
            'guest_ids' => 'required|array|min:1',
            'guest_ids.*' => 'required|integer|distinct',
            'channels' => 'required|array|min:1',
            'channels.*' => 'required|in:email,sms,whatsapp',
            'consent' => 'accepted',
        ]);
        if ($event->responsesClosed()) {
            throw ValidationException::withMessages(['guest_ids' => 'Invitations cannot be sent after the event has started.']);
        }
        $guests = $event->invitees()->whereKey($data['guest_ids'])->get();
        if ($guests->count() !== count($data['guest_ids'])) {
            throw ValidationException::withMessages(['guest_ids' => 'One or more selected guests do not belong to this event.']);
        }
        $count = 0;
        foreach ($guests as $guest) foreach (array_unique($data['channels']) as $channel) {
            if (($channel === 'email' && ! $guest->email) || ($channel !== 'email' && ! $guest->phone)) continue;
            $delivery = Delivery::firstOrNew(['invitee_id' => $guest->id, 'channel' => $channel]);
            $delivery->fill(['status' => 'queued', 'error' => null, 'provider_id' => null])->save();
            SendInvitation::dispatch($delivery->id);
            $count++;
        }
        return back()->with('success', "Sent {$count} invitation".($count === 1 ? '' : 's').' to the selected guests.');
    }

    public function export(Event $event)
    {
        $this->own($event);
        return response()->streamDownload(function () use ($event) {
            $file = fopen('php://output', 'w');
            fputcsv($file, ['name', 'email', 'phone', 'whatsapp', 'rsvp', 'attending_count', 'participation_package', 'participation_unit_price', 'participation_total', 'participation_currency', 'participation_payment_status', 'participation_payment_reference', 'dietary', 'attendance_status', 'attended_count', 'pledge_amount', 'paid_amount', 'outstanding_balance', 'pledge_currency', 'pledge_status', 'payment_count', 'payment_references', 'pledge_message']);
            foreach ($event->invitees()->with('pledge.payments')->cursor() as $guest) {
                $payments = $guest->pledge?->payments->where('status', 'recorded');
                $unitPrice = $event->participation_payment_timing === 'free' ? null : (float) ($guest->package_unit_price_snapshot ?? $event->participation_price ?? 0);
                $participationCurrency = $event->participation_payment_timing === 'free' ? null : ($guest->package_currency_snapshot ?: $event->participation_currency);
                $row = [$guest->name, $guest->email, $guest->phone, data_get($guest->rsvp_answers, 'whatsapp'), $guest->rsvp_status, $guest->attending_count, $guest->package_name_snapshot, $unitPrice, $unitPrice === null ? null : $unitPrice * (int) $guest->attending_count, $participationCurrency, $guest->participation_payment_status, $guest->participation_payment_reference, $guest->dietary, $guest->attendance_status, $guest->attended_count, $guest->pledge?->amount, $guest->pledge?->paid_amount, $guest->pledge?->balance, $guest->pledge ? $event->pledge_currency : null, $guest->pledge?->status, $payments?->count(), $payments?->pluck('reference')->filter()->implode(' | '), $guest->pledge?->message];
                fputcsv($file, array_map(fn ($value) => preg_match('/^[=+@\-]/', (string) $value) ? "'".$value : $value, $row));
            }
            fclose($file);
        }, 'guest-responses.csv', ['Content-Type' => 'text/csv']);
    }

    public function updateParticipationPayment(Request $request, Event $event, Invitee $guest, EventPricingService $pricing)
    {
        $this->own($event);
        abort_unless((int) $guest->event_id === (int) $event->id, 404);
        abort_unless($event->participation_payment_timing === 'pay_now' && $event->participation_collection_method === 'manual', 404);
        $data = $request->validate(['status' => ['required', 'in:paid,rejected']]);
        DB::transaction(function () use ($event, $guest, $data, $pricing): void {
            $lockedEvent = Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();
            abort_unless($lockedEvent->participation_payment_timing === 'pay_now' && $lockedEvent->participation_collection_method === 'manual', 404);
            $lockedGuest = $lockedEvent->invitees()->whereKey($guest->id)->lockForUpdate()->firstOrFail();
            $confirmation = $data['status'] === 'paid'
                ? $lockedEvent->confirmationStatusFor($lockedGuest->rsvp_status, 'paid', $lockedGuest->confirmation_status)
                : $lockedGuest->confirmation_status;

            if (! $lockedGuest->reservesCapacity()
                && $lockedGuest->rsvp_status === 'accepted'
                && $confirmation !== 'rejected') {
                $package = $this->lockCapacityPackage($lockedEvent, $lockedGuest);
                $pricing->assertAvailable($lockedEvent, $package, (int) $lockedGuest->attending_count, $lockedGuest);
            }

            $lockedGuest->update([
                'participation_payment_status' => $data['status'],
                'confirmation_status' => $confirmation,
                'access_pass_issued_at' => $data['status'] === 'paid'
                    && $lockedGuest->rsvp_status === 'accepted'
                    && $confirmation === 'confirmed'
                        ? ($lockedGuest->access_pass_issued_at ?? now())
                        : null,
            ]);
        });
        return back()->with('success', $data['status'] === 'paid' ? 'Participation payment verified.' : 'Participation payment rejected.');
    }

    public function updateRsvpConfirmation(Request $request, Event $event, Invitee $guest, EventPricingService $pricing)
    {
        $this->own($event);
        abort_unless((int) $guest->event_id === (int) $event->id, 404);
        $data = $request->validate(['confirmation_status' => ['required', 'in:confirmed,rejected']]);
        $message = DB::transaction(function () use ($event, $guest, $data, $pricing): string {
            $lockedEvent = Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();
            $lockedGuest = $lockedEvent->invitees()->whereKey($guest->id)->lockForUpdate()->firstOrFail();

            if ($data['confirmation_status'] === 'rejected') {
                $paymentAttempts = $lockedGuest->participationPayments()
                    ->whereIn('status', ['initiated', 'pending', 'paid', 'failed', 'request_failed'])
                    ->lockForUpdate()
                    ->get();
                $paymentAlreadyStarted = $paymentAttempts->contains(
                    fn ($payment) => in_array($payment->status, ['initiated', 'pending', 'paid'], true),
                );
                if ($paymentAlreadyStarted || in_array($lockedGuest->participation_payment_status, ['payment_reported', 'pending', 'paid'], true)) {
                    throw ValidationException::withMessages([
                        'confirmation_status' => 'Payment has already been reported or initiated. Resolve the payment with the participant before rejecting this registration.',
                    ]);
                }
                $paymentAttempts
                    ->whereIn('status', ['failed', 'request_failed'])
                    ->each->update(['status' => 'superseded']);
                $lockedGuest->update(['confirmation_status' => 'rejected', 'access_pass_issued_at' => null]);

                return 'Registration rejected.';
            }

            if ($lockedGuest->rsvp_status !== 'accepted') {
                throw ValidationException::withMessages(['confirmation_status' => 'Only an accepted attendance request can be approved.']);
            }

            if (! $lockedGuest->reservesCapacity()) {
                $package = $this->lockCapacityPackage($lockedEvent, $lockedGuest);
                $pricing->assertAvailable($lockedEvent, $package, (int) $lockedGuest->attending_count, $lockedGuest);
            }

            $paymentStillRequired = $lockedEvent->participation_payment_timing === 'pay_now'
                && ! in_array($lockedGuest->participation_payment_status, ['paid', 'not_required'], true);
            $confirmationStatus = $paymentStillRequired ? 'awaiting_payment' : 'confirmed';
            $lockedGuest->update([
                'confirmation_status' => $confirmationStatus,
                'access_pass_issued_at' => null,
            ]);
            if ($lockedGuest->accessPassEligible()) $lockedGuest->update(['access_pass_issued_at' => now()]);

            return $paymentStillRequired
                ? 'Registration approved. The participant can now review the total and complete payment.'
                : 'Registration approved and QR pass enabled.';
        });

        return back()->with('success', $message);
    }

    private function lockCapacityPackage(Event $event, Invitee $guest): ?EventPackage
    {
        if (! $event->usesPackages()) return null;

        return $event->packages()
            ->whereKey($guest->event_package_id)
            ->lockForUpdate()
            ->first();
    }

    public function updatePledge(Request $request, Event $event, Pledge $pledge)
    {
        $this->own($event);
        abort_unless((int) $pledge->invitee()->value('event_id') === (int) $event->id, 404);
        $data = $request->validate(['status' => ['required', 'in:pledged,payment_reported,cancelled']]);
        abort_if((float) $pledge->paid_amount > 0, 422, 'A pledge with booked payments has a calculated payment status. Void its payments before changing the pledge status.');
        $pledge->update(['status' => $data['status']]);
        return back()->with('success', 'Pledge status updated.');
    }

    public function createPledgePayment(Event $event, Pledge $pledge)
    {
        $this->own($event);
        $this->authorizePledge($event, $pledge);
        abort_unless($event->pledge_enabled, 404);

        return view('events.pledge-payment-create', [
            'event' => $event,
            'pledge' => $pledge->load(['invitee', 'payments' => fn ($query) => $query->latest('payment_date')->latest('id')]),
        ]);
    }

    public function recordPledgePayment(Request $request, Event $event, Pledge $pledge, NotificationDispatcher $notifications)
    {
        $this->own($event);$this->authorizePledge($event, $pledge);
        abort_if($pledge->status === 'cancelled', 422, 'Restore the pledge before recording a payment.');
        $data = $request->validate(['amount' => ['required', 'numeric', 'min:0.01', 'max:999999999999.99'], 'payment_date' => ['required', 'date', 'before_or_equal:today'], 'payment_method' => ['nullable', 'string', 'max:100'], 'reference' => ['nullable', 'string', 'max:150'], 'note' => ['nullable', 'string', 'max:1000'], 'overpayment_override' => ['nullable', 'boolean'], 'overpayment_reason' => ['nullable', 'string', 'max:1000']]);
        [$payment, $becameFullyPaid] = DB::transaction(function () use ($event, $pledge, $data, $request) {
            $locked = Pledge::whereKey($pledge->id)->lockForUpdate()->firstOrFail();
            if (! empty($data['reference']) && PledgePayment::where('reference', $data['reference'])->whereHas('pledge.invitee', fn ($query) => $query->where('event_id', $event->id))->exists()) throw ValidationException::withMessages(['reference' => 'This payment reference is already recorded for this event.']);
            $remaining = max(0, (float) $locked->amount - (float) $locked->paid_amount);
            $overpayment = (float) $data['amount'] > $remaining + 0.0001;
            if ($overpayment && ! $request->boolean('overpayment_override')) throw ValidationException::withMessages(['amount' => 'The amount exceeds the outstanding balance. Enable the override and provide a reason to continue.']);
            if ($overpayment && empty(trim((string) ($data['overpayment_reason'] ?? '')))) throw ValidationException::withMessages(['overpayment_reason' => 'Explain why this overpayment is being accepted.']);
            $wasFullyPaid = in_array($locked->status, ['paid', 'overpaid'], true);
            $payment = $locked->payments()->create(array_merge($data, ['overpayment_override' => $overpayment, 'recorded_by' => auth()->id(), 'status' => 'recorded']));
            $this->recalculatePledge($locked);
            return [$payment, ! $wasFullyPaid && in_array($locked->fresh()->status, ['paid', 'overpaid'], true)];
        });
        $guest = $pledge->invitee;
        if ($event->payment_receipt_enabled && $event->payment_receipt_mode === 'automatic') $notifications->dispatch($guest, 'pledge_payment_receipt', $this->receiptChannels($event), false, $payment);
        if ($becameFullyPaid && $event->pledge_thanks_enabled && $event->pledge_thanks_mode === 'automatic') $notifications->dispatch($guest, 'pledge_fully_paid_thanks', $this->notificationChannels($event, 'pledge'));
        return redirect()->route('events.pledge-payments.create', [$event, $pledge])->with('success', 'Payment booked. Outstanding balance: '.$event->pledge_currency.' '.number_format(max(0, (float) $pledge->fresh()->balance), 2).'.');
    }

    public function voidPledgePayment(Request $request, Event $event, Pledge $pledge, PledgePayment $payment)
    {
        $this->own($event);$this->authorizePledge($event, $pledge);abort_unless((int) $payment->pledge_id === (int) $pledge->id, 404);
        $data = $request->validate(['void_reason' => ['required', 'string', 'max:1000']]);
        abort_if($payment->status === 'voided', 422, 'This payment is already voided.');
        DB::transaction(function () use ($pledge, $payment, $data) {$payment->update(['status' => 'voided', 'voided_at' => now(), 'voided_by' => auth()->id(), 'void_reason' => $data['void_reason']]);$this->recalculatePledge($pledge);});
        return back()->with('success', 'Payment voided and pledge balance recalculated.');
    }

    public function sendPaymentReceipt(Request $request, Event $event, Pledge $pledge, PledgePayment $payment, NotificationDispatcher $notifications)
    {
        $this->own($event);$this->authorizePledge($event, $pledge);abort_unless((int) $payment->pledge_id === (int) $pledge->id && $payment->status === 'recorded', 404);abort_unless($event->payment_receipt_enabled, 422, 'Enable payment receipts in invitation settings before sending.');
        $count = $notifications->dispatch($pledge->invitee, 'pledge_payment_receipt', $this->receiptChannels($event), $request->boolean('resend'), $payment);
        return back()->with('success', $count ? "Queued {$count} payment receipt".($count === 1 ? '.' : 's.') : 'This payment receipt was already queued or sent.');
    }

    public function updateAttendance(Request $request, Event $event, Invitee $guest, NotificationDispatcher $notifications)
    {
        $this->own($event);
        abort_unless((int) $guest->event_id === (int) $event->id, 404);
        if (! $event->responsesClosed()) throw ValidationException::withMessages(['attendance_status' => 'Attendance can be confirmed after the event starts.']);
        $data = $request->validate(['attendance_status' => ['required', 'in:pending,attended,absent'], 'attended_count' => ['nullable', 'integer', 'min:0', 'max:'.$guest->max_guests]]);
        $attendedCount = $data['attendance_status'] === 'attended' ? max(1, (int) ($data['attended_count'] ?? 1)) : 0;
        $becameAttended = $data['attendance_status'] === 'attended' && $guest->attendance_status !== 'attended';
        $guest->update(['attendance_status' => $data['attendance_status'], 'attended_count' => $attendedCount, 'attendance_confirmed_at' => $data['attendance_status'] === 'pending' ? null : now(), 'attendance_confirmed_by' => $data['attendance_status'] === 'pending' ? null : auth()->id()]);
        if ($becameAttended && $event->attendance_thanks_enabled && $event->attendance_thanks_mode === 'automatic') {
            $notifications->dispatch($guest, 'attendance_thanks', $this->notificationChannels($event, 'attendance'));
        }
        return back()->with('success', 'Attendance updated.');
    }

    public function sendThanks(Request $request, Event $event, Invitee $guest, NotificationDispatcher $notifications)
    {
        $this->own($event);
        abort_unless((int) $guest->event_id === (int) $event->id, 404);
        $type = $request->validate(['type' => ['required', 'in:attendance_thanks,pledge_fully_paid_thanks'], 'resend' => ['nullable', 'boolean']])['type'];
        if ($type === 'attendance_thanks') abort_unless($guest->attendance_status === 'attended', 422, 'Confirm attendance before sending thanks.');
        if ($type === 'pledge_fully_paid_thanks') abort_unless(in_array($guest->pledge?->status, ['paid','overpaid'], true), 422, 'The pledge must be fully paid before sending thanks.');
        $prefix = $type === 'attendance_thanks' ? 'attendance' : 'pledge';
        abort_unless($event->{$prefix.'_thanks_enabled'}, 422, 'Enable this notification in the invitation settings before sending it.');
        $count = $notifications->dispatch($guest, $type, $this->notificationChannels($event, $prefix), $request->boolean('resend'));
        return back()->with('success', $count ? "Queued {$count} thank-you notification".($count === 1 ? '.' : 's.') : 'Thank-you notification was already queued or sent.');
    }

    public function bulkUpdateAttendance(Request $request, Event $event, NotificationDispatcher $notifications)
    {
        $this->own($event);
        if (! $event->responsesClosed()) throw ValidationException::withMessages(['attendance_status' => 'Attendance can be confirmed after the event starts.']);
        $data = $request->validate(['guest_ids' => ['required', 'array', 'min:1'], 'guest_ids.*' => ['integer', 'distinct'], 'attendance_status' => ['required', 'in:pending,attended,absent'], 'attended_count' => ['nullable', 'integer', 'min:0', 'max:10']]);
        $guests = $event->invitees()->whereKey($data['guest_ids'])->get();
        if ($guests->count() !== count($data['guest_ids'])) throw ValidationException::withMessages(['guest_ids' => 'One or more selected participants do not belong to this event.']);
        foreach ($guests as $guest) {
            $becameAttended = $data['attendance_status'] === 'attended' && $guest->attendance_status !== 'attended';
            $count = $data['attendance_status'] === 'attended' ? min($guest->max_guests, max(1, (int) ($data['attended_count'] ?? 1))) : 0;
            $guest->update(['attendance_status' => $data['attendance_status'], 'attended_count' => $count, 'attendance_confirmed_at' => $data['attendance_status'] === 'pending' ? null : now(), 'attendance_confirmed_by' => $data['attendance_status'] === 'pending' ? null : auth()->id()]);
            if ($becameAttended && $event->attendance_thanks_enabled && $event->attendance_thanks_mode === 'automatic') $notifications->dispatch($guest, 'attendance_thanks', $this->notificationChannels($event, 'attendance'));
        }
        return back()->with('success', 'Attendance updated for '.$guests->count().' participant'.($guests->count() === 1 ? '.' : 's.'));
    }

    public function bulkSendThanks(Request $request, Event $event, NotificationDispatcher $notifications)
    {
        $this->own($event);
        $data = $request->validate(['guest_ids' => ['required', 'array', 'min:1'], 'guest_ids.*' => ['integer', 'distinct'], 'type' => ['required', 'in:attendance_thanks,pledge_fully_paid_thanks']]);
        $guests = $event->invitees()->with('pledge')->whereKey($data['guest_ids'])->get();
        if ($guests->count() !== count($data['guest_ids'])) throw ValidationException::withMessages(['guest_ids' => 'One or more selected participants do not belong to this event.']);
        $eligible = $guests->filter(fn ($guest) => $data['type'] === 'attendance_thanks' ? $guest->attendance_status === 'attended' : in_array($guest->pledge?->status, ['paid','overpaid'], true));
        $prefix = $data['type'] === 'attendance_thanks' ? 'attendance' : 'pledge';
        abort_unless($event->{$prefix.'_thanks_enabled'}, 422, 'Enable this notification in the invitation settings before sending it.');
        $count = $eligible->sum(fn ($guest) => $notifications->dispatch($guest, $data['type'], $this->notificationChannels($event, $prefix)));
        return back()->with('success', $count ? "Queued {$count} thank-you notification".($count === 1 ? '.' : 's.') : 'No eligible unsent notifications were found.');
    }

    private function notificationChannels(Event $event, string $prefix): array
    {
        return array_values(array_filter(['email' => $event->{$prefix.'_thanks_email'} ? 'email' : null, 'sms' => $event->{$prefix.'_thanks_sms'} ? 'sms' : null]));
    }

    private function receiptChannels(Event $event): array
    {
        return array_values(array_filter([$event->payment_receipt_email ? 'email' : null, $event->payment_receipt_sms ? 'sms' : null]));
    }

    private function authorizePledge(Event $event, Pledge $pledge): void
    {
        abort_unless((int) $pledge->invitee()->value('event_id') === (int) $event->id, 404);
    }

    private function recalculatePledge(Pledge $pledge): void
    {
        $paid = (float) $pledge->payments()->where('status', 'recorded')->sum('amount');
        $promised = (float) $pledge->amount;
        $status = $paid <= 0 ? ($pledge->payment_reference ? 'payment_reported' : 'pledged') : ($paid < $promised ? 'partially_paid' : ($paid > $promised ? 'overpaid' : 'paid'));
        $pledge->update(['status' => $status, 'paid_at' => $paid >= $promised ? ($pledge->paid_at ?: now()) : null, 'confirmed_at' => $paid >= $promised ? ($pledge->confirmed_at ?: now()) : null, 'payment_confirmed_by' => $paid >= $promised ? auth()->id() : null]);
    }

    private function validatePaidEventRequirements(User $organiser, array $data): void
    {
        if (($data['participation_payment_timing'] ?? 'free') === 'free') return;

        $organiser->loadMissing('organiserVerification');
        if (($data['visibility'] ?? 'invite_only') === 'link' && ! $organiser->isVerifiedOrganiser()) {
            throw ValidationException::withMessages([
                'visibility' => 'Verify your identity or organisation before making a paid event public. Standard organisers can still run paid invite-only events using manual collection or payment at the event.',
            ]);
        }
        if (empty(trim((string) ($data['contact'] ?? '')))) {
            throw ValidationException::withMessages(['contact' => 'Add a support contact for guests before configuring a paid event.']);
        }
        if (empty(trim((string) ($data['cancellation_policy'] ?? '')))) {
            throw ValidationException::withMessages(['cancellation_policy' => 'Add a clear cancellation and refund policy for this paid event.']);
        }
        if (($data['participation_payment_timing'] ?? null) === 'pay_now'
            && ($data['participation_collection_method'] ?? 'bluetick_pay') === 'bluetick_pay'
            && ! $organiser->canCollectParticipationPayments()) {
            throw ValidationException::withMessages([
                'payout_number' => 'Bluetick Pay requires organiser verification and a verified settlement account name. Choose manual collection for an invite-only event, or complete verification.',
            ]);
        }
    }

    private function paymentConfigurationChanged(Event $event, array $data, ?array $packages = null): bool
    {
        $timing = (string) ($data['participation_payment_timing'] ?? $event->participation_payment_timing ?? 'free');
        $method = (string) ($data['participation_collection_method'] ?? $event->participation_collection_method ?? 'bluetick_pay');
        $pricingMode = (string) ($data['pricing_mode'] ?? $event->pricing_mode ?? 'single');
        $price = (float) ($data['participation_price'] ?? $event->participation_price ?? 0);
        $contact = array_key_exists('contact', $data) ? $data['contact'] : $event->contact;

        $changed = $timing !== (string) ($event->participation_payment_timing ?? 'free')
            || (string) ($data['visibility'] ?? $event->visibility ?? 'invite_only') !== (string) ($event->visibility ?? 'invite_only')
            || trim((string) $contact) !== trim((string) $event->contact)
            || $method !== (string) ($event->participation_collection_method ?? 'bluetick_pay')
            || $pricingMode !== (string) ($event->pricing_mode ?? 'single')
            || abs($price - (float) ($event->participation_price ?? 0)) > .001
            || strtoupper((string) ($data['participation_currency'] ?? $event->participation_currency ?? 'TZS')) !== strtoupper((string) ($event->participation_currency ?? 'TZS'))
            || trim((string) ($data['participation_payment_details'] ?? $event->participation_payment_details)) !== trim((string) $event->participation_payment_details)
            || trim((string) ($data['payout_payment_type'] ?? $event->payout_payment_type)) !== trim((string) $event->payout_payment_type)
            || trim((string) ($data['payout_number'] ?? $event->payout_number)) !== trim((string) $event->payout_number)
            || trim((string) ($data['cancellation_policy'] ?? $event->cancellation_policy)) !== trim((string) $event->cancellation_policy);

        if ($changed || $pricingMode !== 'packages' || $packages === null) return $changed;

        $current = $event->packages()->orderBy('sort_order')->orderBy('id')->get()->map(fn ($package) => [
            'id' => (int) $package->id,
            'name' => trim((string) $package->name),
            'description' => filled($package->description) ? trim((string) $package->description) : null,
            'price' => number_format((float) $package->price, 2, '.', ''),
            'capacity' => $package->capacity === null ? null : (int) $package->capacity,
            'max_per_registration' => $package->max_per_registration === null ? null : (int) $package->max_per_registration,
            'is_active' => (bool) $package->is_active,
            'sort_order' => (int) $package->sort_order,
        ])->all();

        $incoming = array_map(fn (array $package) => [
            'id' => isset($package['id']) ? (int) $package['id'] : null,
            'name' => $package['name'],
            'description' => $package['description'],
            'price' => number_format((float) $package['price'], 2, '.', ''),
            'capacity' => $package['capacity'],
            'max_per_registration' => $package['max_per_registration'],
            'is_active' => $package['is_active'],
            'sort_order' => $package['sort_order'],
        ], $packages);

        return $current !== $incoming;
    }

    /**
     * Validate and normalize the package repeater after Laravel has checked field types.
     *
     * @return array<int, array<string, mixed>>
     */
    private function validatedPackages(
        array $rows,
        string $currency,
        bool $requiresBluetickRules,
        ?Event $event = null,
        int $registrationLimit = 1,
    ): array {
        $packages = [];
        $names = [];
        $knownPackages = $event
            ? $event->packages()->get()->keyBy(fn (EventPackage $package) => (int) $package->id)
            : collect();
        $reservationStats = $event ? $this->packageReservationStats($event) : collect();

        foreach (array_values($rows) as $index => $row) {
            $id = isset($row['id']) && $row['id'] !== '' ? (int) $row['id'] : null;
            $name = trim((string) ($row['name'] ?? ''));
            $description = trim((string) ($row['description'] ?? ''));
            $price = $row['price'] ?? null;
            $capacity = ($row['capacity'] ?? '') === '' ? null : (int) $row['capacity'];
            $maxPerRegistration = ($row['max_per_registration'] ?? '') === '' ? null : (int) $row['max_per_registration'];
            $active = filter_var($row['is_active'] ?? true, FILTER_VALIDATE_BOOL);

            // Ignore a completely blank, newly-added inactive row.
            if ($id === null && ! $active && $name === '' && $description === '' && $price === null && $capacity === null && $maxPerRegistration === null) continue;

            if ($event && $id !== null && ! $knownPackages->has($id)) {
                throw ValidationException::withMessages(["packages.$index.id" => 'This package does not belong to the event.']);
            }
            if ($name === '') {
                throw ValidationException::withMessages(["packages.$index.name" => 'Enter a package name.']);
            }
            if ($price === null || $price === '') {
                throw ValidationException::withMessages(["packages.$index.price" => 'Enter the package price per person.']);
            }
            $normalizedName = mb_strtolower($name);
            if (isset($names[$normalizedName])) {
                throw ValidationException::withMessages(["packages.$index.name" => 'Package names must be unique for this event.']);
            }
            $names[$normalizedName] = true;
            if ($active && $requiresBluetickRules) {
                $packageLimit = min(
                    max(1, $registrationLimit),
                    $maxPerRegistration === null ? max(1, $registrationLimit) : max(1, $maxPerRegistration),
                );
                $this->validateBluetickUnitPrice($price, $packageLimit, "packages.$index.price");
            }
            if ($id !== null) {
                $this->validatePackageReservationLimits(
                    $capacity,
                    $maxPerRegistration,
                    $reservationStats->get($id),
                    "packages.$index",
                );
            }

            $packages[] = [
                'id' => $id,
                'name' => $name,
                'description' => $description !== '' ? $description : null,
                'price' => (float) $price,
                'currency' => strtoupper($currency),
                'capacity' => $capacity,
                'max_per_registration' => $maxPerRegistration,
                'is_active' => $active,
                'sort_order' => count($packages),
            ];
        }

        if (! collect($packages)->contains(fn (array $package) => $package['is_active'])) {
            throw ValidationException::withMessages(['packages' => 'Add at least one active pricing package.']);
        }

        return $packages;
    }

    private function validateBluetickUnitPrice(mixed $price, int $maximumQuantity, string $errorKey): void
    {
        $amount = (float) $price;
        if ($amount < 500) {
            throw ValidationException::withMessages([
                $errorKey => 'Bluetick Pay requires at least TZS 500 per participant.',
            ]);
        }
        if (abs($amount - round($amount)) > 0.000001) {
            throw ValidationException::withMessages([
                $errorKey => 'Bluetick Pay requires whole TZS amounts. Remove cents from this price.',
            ]);
        }

        $maximumQuantity = max(1, $maximumQuantity);
        $maximumUnitPrice = floor(self::MAX_PARTICIPATION_PAYMENT / $maximumQuantity);
        if ($amount > $maximumUnitPrice) {
            throw ValidationException::withMessages([
                $errorKey => 'This price can exceed the maximum supported online payment total for '.$maximumQuantity.' attendees. Lower the price or the attendance limit.',
            ]);
        }
    }

    private function syncPackages(Event $event, array $packages, string $currency): void
    {
        $existing = $event->packages()->lockForUpdate()->get()->keyBy(fn ($package) => (int) $package->id);
        $reservationStats = $this->packageReservationStats($event);
        $retained = [];

        foreach ($packages as $attributes) {
            $id = $attributes['id'] ?? null;
            unset($attributes['id']);
            $attributes['currency'] = strtoupper($currency);

            if ($id !== null) {
                $package = $existing->get((int) $id);
                if (! $package) throw ValidationException::withMessages(['packages' => 'One of the selected packages no longer belongs to this event.']);
                $this->validatePackageReservationLimits(
                    $attributes['capacity'],
                    $attributes['max_per_registration'],
                    $reservationStats->get((int) $id),
                    'packages.'.(int) $attributes['sort_order'],
                );
                $package->update($attributes);
            } else {
                $package = $event->packages()->create($attributes);
            }
            $retained[] = (int) $package->id;
        }

        foreach ($existing->except($retained) as $package) {
            $isReferenced = DB::table('invitees')->where('event_package_id', $package->id)->exists();
            if ($isReferenced) $package->update(['is_active' => false]);
            else $package->delete();
        }
    }

    private function packageReservationStats(Event $event)
    {
        return $event->invitees()
            ->reservingCapacity()
            ->whereNotNull('event_package_id')
            ->selectRaw('event_package_id, SUM(attending_count) AS reserved_admissions, MAX(attending_count) AS largest_registration')
            ->groupBy('event_package_id')
            ->get()
            ->keyBy(fn (Invitee $invitee) => (int) $invitee->event_package_id);
    }

    private function validatePackageReservationLimits(?int $capacity, ?int $maxPerRegistration, mixed $stats, string $fieldPrefix): void
    {
        $reservedAdmissions = (int) ($stats?->reserved_admissions ?? 0);
        if ($capacity !== null && $capacity < $reservedAdmissions) {
            throw ValidationException::withMessages([
                $fieldPrefix.'.capacity' => "This package already reserves {$reservedAdmissions} admission(s). Set capacity to at least {$reservedAdmissions}, or leave it blank.",
            ]);
        }

        $largestRegistration = (int) ($stats?->largest_registration ?? 0);
        if ($maxPerRegistration !== null && $maxPerRegistration < $largestRegistration) {
            throw ValidationException::withMessages([
                $fieldPrefix.'.max_per_registration' => "An existing RSVP reserves {$largestRegistration} attendee(s). Set the maximum to at least {$largestRegistration}, or leave it blank.",
            ]);
        }
    }

    private function manualParticipationTotal(Event $event, string $paymentStatus): float
    {
        $fallbackPrice = (float) ($event->participation_price ?? 0);

        return (float) $event->invitees()
            ->where('participation_payment_status', $paymentStatus)
            ->selectRaw('COALESCE(SUM(attending_count * COALESCE(package_unit_price_snapshot, ?)), 0) AS total', [$fallbackPrice])
            ->value('total');
    }

    private function normaliseVenueSelection(Request $request, array &$data, ?Event $event = null): void
    {
        $fields = ['venue_latitude', 'venue_longitude', 'venue_source'];
        $input = $request->all();
        $selectionWasSubmitted = collect($fields)->contains(fn (string $field) => array_key_exists($field, $input));
        $venueChanged = array_key_exists('venue', $data)
            && $event !== null
            && trim((string) $data['venue']) !== trim((string) $event->venue);

        if (! $selectionWasSubmitted) {
            // A manually edited venue must never retain the previous selected
            // map point. If the venue itself was not changed, preserve the
            // existing selection for older/non-JavaScript forms.
            if ($venueChanged) {
                foreach ($fields as $field) $data[$field] = null;
            }

            return;
        }

        if (($data['venue_latitude'] ?? null) === null
            || ($data['venue_longitude'] ?? null) === null
            || ($data['venue_source'] ?? null) !== 'nominatim') {
            foreach ($fields as $field) $data[$field] = null;

            return;
        }

        $data['venue_latitude'] = (float) $data['venue_latitude'];
        $data['venue_longitude'] = (float) $data['venue_longitude'];
    }
}
