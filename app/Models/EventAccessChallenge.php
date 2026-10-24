<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EventAccessChallenge extends Model
{
    protected $guarded = [];
    protected $hidden = ['code_hash', 'token', 'destination'];
    protected function casts(): array { return ['expires_at' => 'datetime', 'verified_at' => 'datetime']; }
    public function event() { return $this->belongsTo(Event::class); }
    public function invitee() { return $this->belongsTo(Invitee::class); }
}
