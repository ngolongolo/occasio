<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class AdminAuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'actor_id',
        'subject_user_id',
        'auditable_type',
        'auditable_id',
        'action',
        'reason',
        'before_state',
        'after_state',
        'context',
        'ip_address',
        'user_agent',
    ];

    protected $hidden = ['reason', 'ip_address', 'user_agent'];

    protected function casts(): array
    {
        return [
            'reason' => 'encrypted',
            'before_state' => 'array',
            'after_state' => 'array',
            'context' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Administrator audit records are immutable.'));
        static::deleting(fn () => throw new LogicException('Administrator audit records are immutable.'));
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function subject()
    {
        return $this->belongsTo(User::class, 'subject_user_id');
    }

    public function auditable()
    {
        return $this->morphTo();
    }

    public function scopeForSubject(Builder $query, User|int $subject): Builder
    {
        return $query->where('subject_user_id', $subject instanceof User ? $subject->getKey() : $subject);
    }
}
