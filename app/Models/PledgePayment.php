<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PledgePayment extends Model
{
    protected $fillable = ['amount', 'payment_date', 'payment_method', 'reference', 'note', 'overpayment_override', 'overpayment_reason', 'status', 'recorded_by', 'voided_at', 'voided_by', 'void_reason'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'payment_date' => 'date', 'overpayment_override' => 'boolean', 'voided_at' => 'datetime'];
    }

    public function pledge()
    {
        return $this->belongsTo(Pledge::class);
    }
}
