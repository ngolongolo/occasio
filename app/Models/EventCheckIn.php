<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EventCheckIn extends Model
{
    protected $guarded = [];
    protected function casts(): array { return ['scanned_at' => 'datetime']; }
    public function event() { return $this->belongsTo(Event::class); }
    public function invitee() { return $this->belongsTo(Invitee::class); }
    public function operator() { return $this->belongsTo(User::class, 'operator_id'); }
}
