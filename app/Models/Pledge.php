<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Pledge extends Model
{
    protected $fillable = ['amount', 'payment_method', 'payment_reference', 'message', 'status', 'pledged_at', 'confirmed_at', 'paid_at', 'payment_confirmed_by'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'pledged_at' => 'datetime', 'confirmed_at' => 'datetime', 'paid_at' => 'datetime'];
    }

    public function invitee()
    {
        return $this->belongsTo(Invitee::class);
    }

    public function payments()
    {
        return $this->hasMany(PledgePayment::class);
    }

    public function getPaidAmountAttribute(): string
    {
        return number_format((float) $this->payments()->where('status', 'recorded')->sum('amount'), 2, '.', '');
    }

    public function getBalanceAttribute(): string
    {
        return number_format((float) $this->amount - (float) $this->paid_amount, 2, '.', '');
    }
}
