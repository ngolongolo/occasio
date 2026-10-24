<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ParticipationPayment extends Model
{
    protected $guarded = [];
    protected $hidden = ['callback_token'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2', 'request_response' => 'array', 'callback_payload' => 'array',
            'requested_at' => 'datetime', 'provider_payment_date' => 'date', 'paid_at' => 'datetime', 'failed_at' => 'datetime',
        ];
    }

    public function invitee() { return $this->belongsTo(Invitee::class); }
    public function checkoutAcknowledgement() { return $this->belongsTo(CheckoutAcknowledgement::class); }
}
