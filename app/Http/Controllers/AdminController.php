<?php

namespace App\Http\Controllers;

use App\Models\{Delivery, Event, Invitee, PledgePayment, User};
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminController
{
    public function index()
    {
        return view('admin.index', [
            'stats' => $this->stats(),
            'recentEvents' => Event::with('user')->withCount('invitees')->latest()->limit(10)->get(),
            'recentDeliveries' => Delivery::with('invitee.event')->latest()->limit(10)->get(),
        ]);
    }

    public function users(Request $request)
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'role' => ['nullable', Rule::in(['organiser', 'super_admin'])]]);
        $users = User::withCount('events')->when($filters['q'] ?? null, function ($query, $search) {
            $query->where(fn ($query) => $query->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"));
        })->when($filters['role'] ?? null, fn ($query, $role) => $query->where('role', $role))->latest()->paginate(20)->withQueryString();

        return view('admin.users', compact('users', 'filters'));
    }

    public function events(Request $request)
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'state' => ['nullable', Rule::in(['upcoming', 'completed'])]]);
        $events = Event::with('user')->withCount('invitees')->when($filters['q'] ?? null, function ($query, $search) {
            $query->where(fn ($query) => $query->where('title', 'like', "%{$search}%")->orWhere('host', 'like', "%{$search}%")->orWhere('venue', 'like', "%{$search}%")->orWhereHas('user', fn ($user) => $user->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%")));
        })->when(($filters['state'] ?? null) === 'upcoming', fn ($query) => $query->where('starts_at', '>=', now()))
            ->when(($filters['state'] ?? null) === 'completed', fn ($query) => $query->where('starts_at', '<', now()))
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

    public function payments(Request $request)
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', Rule::in(['recorded', 'voided'])]]);
        $payments = PledgePayment::with('pledge.invitee.event.user')->when($filters['q'] ?? null, function ($query, $search) {
            $query->where(fn ($query) => $query->where('reference', 'like', "%{$search}%")->orWhereHas('pledge.invitee', fn ($invitee) => $invitee->where('name', 'like', "%{$search}%")->orWhereHas('event', fn ($event) => $event->where('title', 'like', "%{$search}%"))));
        })->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))->latest('payment_date')->latest('id')->paginate(25)->withQueryString();

        return view('admin.payments', compact('payments', 'filters'));
    }

    public function updateRole(Request $request, User $user)
    {
        $data = $request->validate(['role' => ['required', Rule::in(['organiser', 'super_admin'])]]);
        if ($user->is($request->user()) && $data['role'] !== 'super_admin') {
            return back()->withErrors(['role' => 'You cannot remove your own Super Admin access.']);
        }
        if ($user->isSuperAdmin() && $data['role'] === 'organiser' && User::where('role', 'super_admin')->count() <= 1) {
            return back()->withErrors(['role' => 'At least one Super Admin must remain active.']);
        }
        $user->forceFill(['role' => $data['role']])->save();

        return back()->with('success', $user->name.' is now '.str_replace('_', ' ', $data['role']).'.');
    }

    private function stats(): array
    {
        return ['organisers' => User::where('role', 'organiser')->count(), 'super_admins' => User::where('role', 'super_admin')->count(), 'events' => Event::count(), 'participants' => Invitee::count(), 'deliveries' => Delivery::count(), 'payments' => PledgePayment::where('status', 'recorded')->count()];
    }
}
