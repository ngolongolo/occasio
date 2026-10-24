<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Event extends Model
{
    public function __construct(array $attributes = []) { parent::__construct($attributes); $this->mergeFillable(['rsvp_form_preset','rsvp_confirmation_mode','rsvp_field_config','pricing_mode']); }
    protected $fillable = ['user_id', 'event_type', 'title', 'host', 'description', 'starts_at', 'ends_at', 'rsvp_deadline', 'capacity', 'visibility', 'plus_ones_allowed', 'max_attendees_per_registration', 'venue', 'venue_latitude', 'venue_longitude', 'venue_source', 'dress_code', 'contact', 'what_to_bring', 'primary_color', 'background_color', 'text_color', 'logo_path', 'invitation_card_path', 'invitation_card_preview_path', 'invitation_card_name', 'invitation_card_mime', 'sms_template', 'participation_enabled', 'participation_payment_timing', 'participation_collection_method', 'participation_price', 'participation_currency', 'participation_payment_details', 'participation_payment_url', 'cancellation_policy', 'payout_payment_type', 'payout_number', 'pledge_enabled', 'pledge_currency', 'pledge_message', 'payment_details', 'attendance_thanks_enabled', 'attendance_thanks_mode', 'attendance_thanks_email', 'attendance_thanks_sms', 'attendance_email_subject', 'attendance_email_message', 'attendance_sms_message', 'pledge_thanks_enabled', 'pledge_thanks_mode', 'pledge_thanks_email', 'pledge_thanks_sms', 'pledge_email_subject', 'pledge_email_message', 'pledge_sms_message', 'payment_receipt_enabled', 'payment_receipt_mode', 'payment_receipt_email', 'payment_receipt_sms', 'payment_receipt_email_subject', 'payment_receipt_email_message', 'payment_receipt_sms_message'];

    protected static function booted(): void
    {
        static::creating(function (Event $event) {
            $event->uuid ??= (string) Str::uuid();
            $event->registration_token ??= Str::random(64);
            if (! $event->public_code) {
                $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
                do {
                    $code = '';
                    for ($i = 0; $i < 5; $i++) $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
                } while (Event::where('public_code', $code)->exists());
                $event->public_code = $code;
            }
        });
    }

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'rsvp_deadline' => 'date', 'plus_ones_allowed' => 'boolean', 'participation_enabled' => 'boolean', 'participation_price' => 'decimal:2', 'venue_latitude' => 'float', 'venue_longitude' => 'float', 'pledge_enabled' => 'boolean', 'attendance_thanks_enabled' => 'boolean', 'attendance_thanks_email' => 'boolean', 'attendance_thanks_sms' => 'boolean', 'pledge_thanks_enabled' => 'boolean', 'pledge_thanks_email' => 'boolean', 'pledge_thanks_sms' => 'boolean', 'payment_receipt_enabled' => 'boolean', 'payment_receipt_email' => 'boolean', 'payment_receipt_sms' => 'boolean'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function invitees()
    {
        return $this->hasMany(Invitee::class);
    }

    public function packages()
    {
        return $this->hasMany(EventPackage::class)->orderBy('sort_order')->orderBy('id');
    }

    public function activePackages()
    {
        return $this->packages()->where('is_active', true);
    }

    public function usesPackages(): bool
    {
        return $this->pricing_mode === 'packages';
    }

    public function accessChallenges()
    {
        return $this->hasMany(EventAccessChallenge::class);
    }

    public function checkIns()
    {
        return $this->hasMany(EventCheckIn::class);
    }

    public function participationPayments() { return $this->hasManyThrough(ParticipationPayment::class, Invitee::class); }
    public function settlements() { return $this->hasMany(EventSettlement::class); }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function pledges()
    {
        return $this->hasManyThrough(Pledge::class, Invitee::class);
    }

    public function registrationUrl(): string
    {
        return route('events.registration.show', $this->public_code);
    }

    public function responsesClosed(): bool
    {
        return $this->starts_at->isPast();
    }

    public function registrationPartyLimit(): int
    {
        return $this->plus_ones_allowed ? max(1, min(10, (int) ($this->max_attendees_per_registration ?: 2))) : 1;
    }

    public function remainingCapacity(): ?int
    {
        if (! $this->capacity) return null;
        $reserved = (int) $this->invitees()->reservingCapacity()->sum('attending_count');
        return max(0, (int) $this->capacity - $reserved);
    }

    public function rsvpFields(): array
    {
        $defaults=['email'=>['enabled'=>true,'required'=>false,'label'=>'Email address'],'phone'=>['enabled'=>true,'required'=>false,'label'=>'Phone number'],'whatsapp'=>['enabled'=>false,'required'=>false,'label'=>'WhatsApp number'],'dietary'=>['enabled'=>true,'required'=>false,'label'=>'Dietary requirements or meal preferences'],'organization'=>['enabled'=>false,'required'=>false,'label'=>'Organization'],'job_title'=>['enabled'=>false,'required'=>false,'label'=>'Job title'],'accessibility'=>['enabled'=>false,'required'=>false,'label'=>'Accessibility requirements'],'notes'=>['enabled'=>false,'required'=>false,'label'=>'Additional notes']];
        $config=is_array($this->rsvp_field_config)?$this->rsvp_field_config:(json_decode((string)$this->rsvp_field_config,true)?:[]);
        foreach($config as $key=>$value)if(isset($defaults[$key])&&is_array($value))$defaults[$key]=array_merge($defaults[$key],$value);
        return $defaults;
    }
    public function confirmationStatusFor(string $rsvpStatus,string $paymentStatus,?string $current=null):string
    {
        if($rsvpStatus==='declined')return 'rejected';
        if($this->rsvp_confirmation_mode==='organizer_approval'){
            if($current==='confirmed')return 'confirmed';
            if($current==='awaiting_payment'){
                if($this->participation_payment_timing==='pay_now'&&!in_array($paymentStatus,['paid','not_required'],true))return 'awaiting_payment';
                return 'confirmed';
            }
            return 'awaiting_approval';
        }
        if($this->participation_payment_timing==='pay_now'&&!in_array($paymentStatus,['paid','not_required'],true))return 'awaiting_payment';
        return 'confirmed';
    }
}
