<?php

namespace Tests\Feature;

use App\Models\{Delivery, Event, EventSettlement, Invitee, OrganiserVerification, ParticipationPayment, PledgePayment, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminReportingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $organiser;
    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create(['name' => 'System Operator', 'email' => 'operator@example.com', 'password' => 'passwordlong']);
        $this->admin->forceFill(['role' => 'super_admin'])->save();
        $this->organiser = User::create(['name' => 'Coastal Events', 'email' => 'coastal@example.com', 'password' => 'passwordlong']);
        $this->organiser->forceFill(['role' => 'organiser'])->save();
        $this->event = Event::create([
            'user_id' => $this->organiser->id,
            'title' => 'Coastal Summit',
            'host' => 'Coastal Events',
            'description' => 'A professional event.',
            'starts_at' => now()->addWeek(),
            'rsvp_deadline' => now()->addDays(4),
            'venue' => 'Dar es Salaam',
        ]);
    }

    public function test_dashboard_presents_actionable_platform_reporting_without_combining_currencies(): void
    {
        $guest = $this->guest('Confirmed Guest', 3, 'paid');
        $this->payment($guest, 'PAY-TZS', 'TZS', 15000, 'paid');
        $usdGuest = $this->guest('USD Guest', 1, 'paid');
        $this->payment($usdGuest, 'PAY-USD', 'USD', 20, 'paid');
        EventSettlement::create([
            'event_id' => $this->event->id,
            'requested_by' => $this->organiser->id,
            'reference' => 'SET-TZS',
            'callback_token' => Str::random(32),
            'amount' => 4000,
            'currency' => 'TZS',
            'payment_type' => 'MixxByYas',
            'payment_number' => '255656000000',
            'account_name' => 'Coastal Events',
            'status' => 'pending',
        ]);
        $this->organiser->organiserVerification()->create([
            'organiser_type' => OrganiserVerification::TYPE_PROFESSIONAL,
            'status' => OrganiserVerification::STATUS_PENDING,
            'submitted_at' => now()->subDays(3),
        ]);
        Delivery::create(['invitee_id' => $guest->id, 'channel' => 'email', 'status' => 'failed', 'error' => 'Mailbox unavailable']);

        $response = $this->actingAs($this->admin)->get(route('admin.index'));

        $response->assertOk()
            ->assertSee('System overview')
            ->assertSee('Action queue')
            ->assertSee('RSVP funnel')
            ->assertSee('TZS 11,000.00')
            ->assertSee('USD 20.00')
            ->assertSee('Coastal Summit');
        $response->assertViewHas('kpis', fn (array $kpis) => $kpis['pending_verifications'] === 1
            && $kpis['recent_confirmed_seats'] === 4
            && $kpis['delivery_failures'] === 1
            && $kpis['pending_settlements'] === 1);
        $response->assertViewHas('activity', fn (array $activity) => count($activity) === 30);
    }

    public function test_participation_payment_ledger_filters_and_summarises_the_correct_payment_source(): void
    {
        $paidGuest = $this->guest('Asha Paid', 1, 'paid');
        $pendingGuest = $this->guest('Baraka Pending', 1, 'pending');
        $this->payment($paidGuest, 'TZS-PAID', 'TZS', 10000, 'paid');
        $this->payment($pendingGuest, 'USD-PENDING', 'USD', 25, 'pending');

        $response = $this->actingAs($this->admin)->get(route('admin.payments', ['currency' => 'TZS', 'status' => 'paid']));

        $response->assertOk()
            ->assertSee('Participation collections')
            ->assertSee('TZS-PAID')
            ->assertSee('TZS 10,000.00')
            ->assertDontSee('USD-PENDING')
            ->assertDontSee('Pledge payment ledger');
        $response->assertViewHas('payments', fn ($payments) => $payments->total() === 1);
        $response->assertViewHas('paymentSummary', fn ($summary) => $summary->count() === 1
            && $summary->first()->currency === 'TZS'
            && $summary->first()->status === 'paid');
    }

    public function test_settlement_and_pledge_ledgers_are_separate_filterable_admin_reports(): void
    {
        EventSettlement::create([
            'event_id' => $this->event->id,
            'requested_by' => $this->organiser->id,
            'reference' => 'PAYOUT-FAILED',
            'callback_token' => Str::random(32),
            'amount' => 7000,
            'currency' => 'TZS',
            'payment_type' => 'AirtelMoney',
            'payment_number' => '255689123456',
            'account_name' => 'Event Treasurer',
            'status' => 'failed',
            'remarks' => 'Provider rejected request',
        ]);
        EventSettlement::create([
            'event_id' => $this->event->id,
            'requested_by' => $this->organiser->id,
            'reference' => 'PAYOUT-PAID',
            'callback_token' => Str::random(32),
            'amount' => 3000,
            'currency' => 'TZS',
            'payment_type' => 'AirtelMoney',
            'payment_number' => '255689123456',
            'status' => 'paid',
        ]);
        $pledge = $this->guest('Pledge Guest')->pledge()->create(['amount' => 50000, 'status' => 'partially_paid', 'pledged_at' => now()]);
        $pledge->payments()->create([
            'amount' => 10000,
            'payment_date' => now()->toDateString(),
            'reference' => 'PLEDGE-ONLY',
            'status' => 'recorded',
            'recorded_by' => $this->organiser->id,
        ]);

        $this->actingAs($this->admin)->get(route('admin.settlements', ['status' => 'failed']))
            ->assertOk()
            ->assertSee('Settlement requests')
            ->assertSee('PAYOUT-FAILED')
            ->assertSee('••••••••3456')
            ->assertDontSee('PAYOUT-PAID');
        $this->actingAs($this->admin)->get(route('admin.pledges', ['status' => 'recorded']))
            ->assertOk()
            ->assertSee('Pledge payment ledger')
            ->assertSee('PLEDGE-ONLY')
            ->assertDontSee('Participation collections');
    }

    private function guest(string $name, int $attendingCount = 1, string $paymentStatus = 'not_required'): Invitee
    {
        return $this->event->invitees()->create([
            'name' => $name,
            'email' => Str::slug($name).'@example.com',
            'identity_key' => Str::uuid(),
            'token' => Str::random(64),
            'rsvp_status' => 'accepted',
            'confirmation_status' => 'confirmed',
            'participation_payment_status' => $paymentStatus,
            'attending_count' => $attendingCount,
            'responded_at' => now(),
        ]);
    }

    private function payment(Invitee $guest, string $reference, string $currency, float $amount, string $status): ParticipationPayment
    {
        return $guest->participationPayments()->create([
            'reference' => $reference,
            'callback_token' => Str::random(64),
            'msisdn' => '255656000000',
            'network' => 1,
            'amount' => $amount,
            'currency' => $currency,
            'status' => $status,
            'requested_at' => now(),
            'paid_at' => $status === 'paid' ? now() : null,
        ]);
    }
}
