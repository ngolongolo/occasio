<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Event extends Model {protected $fillable=['user_id','title','host','description','starts_at','rsvp_deadline','venue','dress_code','contact'];protected function casts(): array{return ['starts_at'=>'datetime','rsvp_deadline'=>'date'];}public function invitees(){return $this->hasMany(Invitee::class);} }
