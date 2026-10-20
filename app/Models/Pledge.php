<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Pledge extends Model
{
    protected $fillable = ['amount', 'payment_method', 'payment_reference', 'message', 'status', 'pledged_at', 'confirmed_at'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'pledged_at' => 'datetime', 'confirmed_at' => 'datetime'];
    }

    public function invitee()
    {
        return $this->belongsTo(Invitee::class);
    }
}
