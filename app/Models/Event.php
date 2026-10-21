<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Event extends Model
{
    protected $fillable = ['user_id', 'title', 'host', 'description', 'starts_at', 'rsvp_deadline', 'venue', 'dress_code', 'contact', 'primary_color', 'background_color', 'text_color', 'logo_path', 'invitation_card_path', 'invitation_card_preview_path', 'invitation_card_name', 'invitation_card_mime', 'sms_template', 'participation_enabled', 'pledge_enabled', 'pledge_currency', 'pledge_message', 'payment_details', 'attendance_thanks_enabled', 'attendance_thanks_mode', 'attendance_thanks_email', 'attendance_thanks_sms', 'attendance_email_subject', 'attendance_email_message', 'attendance_sms_message', 'pledge_thanks_enabled', 'pledge_thanks_mode', 'pledge_thanks_email', 'pledge_thanks_sms', 'pledge_email_subject', 'pledge_email_message', 'pledge_sms_message', 'payment_receipt_enabled', 'payment_receipt_mode', 'payment_receipt_email', 'payment_receipt_sms', 'payment_receipt_email_subject', 'payment_receipt_email_message', 'payment_receipt_sms_message'];

    protected static function booted(): void
    {
        static::creating(function (Event $event) {
            $event->uuid ??= (string) Str::uuid();
            $event->registration_token ??= Str::random(64);
        });
    }

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'rsvp_deadline' => 'date', 'participation_enabled' => 'boolean', 'pledge_enabled' => 'boolean', 'attendance_thanks_enabled' => 'boolean', 'attendance_thanks_email' => 'boolean', 'attendance_thanks_sms' => 'boolean', 'pledge_thanks_enabled' => 'boolean', 'pledge_thanks_email' => 'boolean', 'pledge_thanks_sms' => 'boolean', 'payment_receipt_enabled' => 'boolean', 'payment_receipt_email' => 'boolean', 'payment_receipt_sms' => 'boolean'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function invitees()
    {
        return $this->hasMany(Invitee::class);
    }

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
        return route('events.registration.show', $this->registration_token);
    }

    public function responsesClosed(): bool
    {
        return $this->starts_at->isPast();
    }
}
