<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CheckoutAcknowledgement extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'snapshot' => 'array',
            'acknowledged_at' => 'datetime',
        ];
    }

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function invitee()
    {
        return $this->belongsTo(Invitee::class);
    }

    public function payments()
    {
        return $this->hasMany(ParticipationPayment::class);
    }
}
