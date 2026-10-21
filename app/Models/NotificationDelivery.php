<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationDelivery extends Model
{
    protected $guarded = [];

    public function invitee()
    {
        return $this->belongsTo(Invitee::class);
    }

    public function pledgePayment()
    {
        return $this->belongsTo(PledgePayment::class);
    }
}
