<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Event extends Model
{
    protected $fillable = ['user_id', 'title', 'host', 'description', 'starts_at', 'rsvp_deadline', 'venue', 'dress_code', 'contact', 'primary_color', 'background_color', 'text_color', 'logo_path', 'invitation_card_path', 'invitation_card_name', 'invitation_card_mime', 'sms_template'];

    protected static function booted(): void
    {
        static::creating(function (Event $event) {
            $event->uuid ??= (string) Str::uuid();
            $event->registration_token ??= Str::random(64);
        });
    }

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'rsvp_deadline' => 'date'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function invitees()
    {
        return $this->hasMany(Invitee::class);
    }

    public function registrationUrl(): string
    {
        return route('events.registration.show', $this->registration_token);
    }
}
