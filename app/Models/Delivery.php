<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Delivery extends Model {protected $guarded=[];public function invitee(){return $this->belongsTo(Invitee::class);} }
