<?php
namespace App\Models;
use App\Notifications\OkesheniResetPassword;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
class User extends Authenticatable {use Notifiable; protected $fillable=['name','email','password'];protected $hidden=['password','remember_token'];protected function casts(): array{return ['password'=>'hashed'];}public function events(){return $this->hasMany(Event::class);}public function isSuperAdmin(): bool{return $this->role==='super_admin';}public function sendPasswordResetNotification($token): void{$this->notify(new OkesheniResetPassword($token));}}
