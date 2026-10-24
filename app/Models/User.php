<?php

namespace App\Models;

use App\Notifications\OkesheniResetPassword;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use Notifiable;

    protected $fillable = ['name', 'email', 'phone', 'password'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return ['password' => 'hashed'];
    }

    public function events()
    {
        return $this->hasMany(Event::class);
    }

    public function organiserVerification()
    {
        return $this->hasOne(OrganiserVerification::class);
    }

    public function adminAuditLogs()
    {
        return $this->hasMany(AdminAuditLog::class, 'actor_id');
    }

    public function subjectAuditLogs()
    {
        return $this->hasMany(AdminAuditLog::class, 'subject_user_id');
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === 'super_admin';
    }

    public function isVerifiedOrganiser(): bool
    {
        return $this->organiserVerification?->isApproved() ?? false;
    }

    public function canCollectParticipationPayments(): bool
    {
        return $this->organiserVerification?->canCollectParticipationPayments() ?? false;
    }

    public function organiserVerificationSummary(): array
    {
        return $this->organiserVerification?->publicSummary() ?? [
            'organiser_type' => OrganiserVerification::TYPE_INDIVIDUAL,
            'verification_level' => OrganiserVerification::LEVEL_NONE,
            'verification_status' => OrganiserVerification::STATUS_UNVERIFIED,
            'public_name' => null,
            'public_contact_email' => null,
            'public_contact_phone' => null,
            'badge_label' => null,
            'badge_description' => null,
            'verified_at' => null,
        ];
    }

    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new OkesheniResetPassword($token));
    }
}
