<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Invitee extends Model
{
    public function __construct(array $attributes = []) { parent::__construct($attributes); $this->mergeFillable(['confirmation_status','rsvp_answers']); }
    protected $fillable = [
        'event_id', 'event_package_id', 'package_name_snapshot', 'package_unit_price_snapshot', 'package_currency_snapshot',
        'name', 'email', 'phone', 'identity_key', 'token', 'access_token', 'access_pass_issued_at', 'max_guests',
        'rsvp_status', 'participation_payment_status', 'participation_payment_reference',
        'participation_payment_reported_at', 'attending_count', 'dietary', 'responded_at',
        'attendance_status', 'attended_count', 'attendance_confirmed_at', 'attendance_confirmed_by',
    ];

    protected $hidden = ['token', 'access_token'];

    protected static function booted(): void
    {
        static::creating(fn (Invitee $invitee) => $invitee->access_token ??= Str::random(64));
    }

    protected function casts(): array
    {
        return ['responded_at' => 'datetime', 'rsvp_answers' => 'array', 'access_pass_issued_at' => 'datetime', 'participation_payment_reported_at' => 'datetime', 'attendance_confirmed_at' => 'datetime', 'package_unit_price_snapshot' => 'decimal:2'];
    }

    public function event() { return $this->belongsTo(Event::class); }
    public function eventPackage() { return $this->belongsTo(EventPackage::class); }
    public function deliveries() { return $this->hasMany(Delivery::class); }
    public function notificationDeliveries() { return $this->hasMany(NotificationDelivery::class); }
    public function pledge() { return $this->hasOne(Pledge::class); }
    public function participationPayments() { return $this->hasMany(ParticipationPayment::class); }
    public function checkoutAcknowledgements() { return $this->hasMany(CheckoutAcknowledgement::class); }
    public function latestParticipationPayment()
    {
        return $this->hasOne(ParticipationPayment::class)
            ->where('status', '!=', 'superseded')
            ->latestOfMany();
    }
    public function checkIns() { return $this->hasMany(EventCheckIn::class); }
    public function scopeReservingCapacity(Builder $query): Builder
    {
        return $query->where('rsvp_status', 'accepted')
            ->where(function (Builder $query): void {
                $query->whereNull('confirmation_status')
                    ->orWhere('confirmation_status', '!=', 'rejected');
            });
    }
    public function reservesCapacity(): bool
    {
        return $this->rsvp_status === 'accepted' && $this->confirmation_status !== 'rejected';
    }
    public function hasPackageQuoteSnapshot(): bool
    {
        return $this->package_unit_price_snapshot !== null && filled($this->package_currency_snapshot);
    }
    public function accessPassEligible(): bool
    {
        if ($this->rsvp_status !== 'accepted' || $this->confirmation_status !== 'confirmed' || $this->attending_count < 1) return false;
        $event = $this->event;
        if ($event->participation_payment_timing === 'pay_now') return $this->participation_payment_status === 'paid';
        return true;
    }
    public function remainingAdmissions(): int { return max(0, (int) $this->attending_count - (int) $this->attended_count); }
    public function rsvpUrl() { return route('rsvp.show', $this->token); }
    public function cardUrl(bool $preview = false)
    {
        $path = $this->event?->invitation_card_path;
        return route('rsvp.card', ['token' => $this->token, 'preview' => $preview ? 1 : 0, 'v' => $path ? substr(sha1($path), 0, 12) : null]);
    }
}
