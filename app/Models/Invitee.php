<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Invitee extends Model {protected $fillable=['event_id','name','email','phone','identity_key','token','max_guests','rsvp_status','attending_count','dietary','responded_at'];protected $hidden=['token'];public function event(){return $this->belongsTo(Event::class);}public function deliveries(){return $this->hasMany(Delivery::class);}public function rsvpUrl(){return route('rsvp.show',$this->token);} }
