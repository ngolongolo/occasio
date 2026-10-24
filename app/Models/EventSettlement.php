<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class EventSettlement extends Model {
    protected $guarded=[]; protected $hidden=['callback_token'];
    protected function casts(): array{return ['amount'=>'decimal:2','request_response'=>'array','callback_payload'=>'array','requested_at'=>'datetime','paid_at'=>'datetime','failed_at'=>'datetime'];}
    public function event(){return $this->belongsTo(Event::class);} public function requester(){return $this->belongsTo(User::class,'requested_by');}
}
