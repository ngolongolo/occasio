<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class OrganiserVerification extends Model
{
    public const TYPE_INDIVIDUAL = 'individual';
    public const TYPE_PROFESSIONAL = 'professional';
    public const TYPE_ORGANISATION = 'organisation';

    public const LEVEL_NONE = 'none';
    public const LEVEL_IDENTITY = 'identity';
    public const LEVEL_BUSINESS = 'business';

    public const STATUS_UNVERIFIED = 'unverified';
    public const STATUS_PENDING = 'pending';
    public const STATUS_CHANGES_REQUESTED = 'changes_requested';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_SUSPENDED = 'suspended';
    public const STATUS_EXPIRED = 'expired';

    public const TYPES = [
        self::TYPE_INDIVIDUAL,
        self::TYPE_PROFESSIONAL,
        self::TYPE_ORGANISATION,
    ];

    public const LEVELS = [
        self::LEVEL_NONE,
        self::LEVEL_IDENTITY,
        self::LEVEL_BUSINESS,
    ];

    public const STATUSES = [
        self::STATUS_UNVERIFIED,
        self::STATUS_PENDING,
        self::STATUS_CHANGES_REQUESTED,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
        self::STATUS_SUSPENDED,
        self::STATUS_EXPIRED,
    ];

    protected $attributes = [
        'organiser_type' => self::TYPE_INDIVIDUAL,
        'verification_level' => self::LEVEL_NONE,
        'status' => self::STATUS_UNVERIFIED,
    ];

    protected $fillable = [
        'organiser_type',
        'verification_level',
        'status',
        'public_name',
        'public_contact_email',
        'public_contact_phone',
        'legal_name',
        'registration_number',
        'tax_identifier',
        'review_notes',
        'decision_message',
        'reviewed_by',
        'submitted_at',
        'reviewed_at',
        'verified_at',
        'expires_at',
        'payout_name_verified_at',
    ];

    protected $hidden = [
        'legal_name',
        'registration_number',
        'tax_identifier',
        'review_notes',
        'decision_message',
    ];

    protected function casts(): array
    {
        return [
            'legal_name' => 'encrypted',
            'registration_number' => 'encrypted',
            'tax_identifier' => 'encrypted',
            'review_notes' => 'encrypted',
            'decision_message' => 'encrypted',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'verified_at' => 'datetime',
            'expires_at' => 'datetime',
            'payout_name_verified_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $verification): void {
            if (! in_array($verification->organiser_type, self::TYPES, true)) {
                throw new InvalidArgumentException('Unsupported organiser type.');
            }

            if (! in_array($verification->verification_level, self::LEVELS, true)) {
                throw new InvalidArgumentException('Unsupported organiser verification level.');
            }

            if (! in_array($verification->status, self::STATUSES, true)) {
                throw new InvalidArgumentException('Unsupported organiser verification status.');
            }
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function auditLogs()
    {
        return $this->morphMany(AdminAuditLog::class, 'auditable')->latest('id');
    }

    public function scopePubliclyVerified(Builder $query): Builder
    {
        return $query
            ->where('status', self::STATUS_APPROVED)
            ->whereIn('verification_level', [self::LEVEL_IDENTITY, self::LEVEL_BUSINESS])
            ->where(function (Builder $query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });
    }

    public function effectiveStatus(): string
    {
        if ($this->status === self::STATUS_APPROVED && $this->expires_at?->isPast()) {
            return self::STATUS_EXPIRED;
        }

        return $this->status;
    }

    public function isApproved(): bool
    {
        return $this->effectiveStatus() === self::STATUS_APPROVED
            && in_array($this->verification_level, [self::LEVEL_IDENTITY, self::LEVEL_BUSINESS], true);
    }

    public function isBusinessVerified(): bool
    {
        return $this->isApproved() && $this->verification_level === self::LEVEL_BUSINESS;
    }

    public function canCollectParticipationPayments(): bool
    {
        return $this->isApproved() && $this->payout_name_verified_at !== null;
    }

    /**
     * An opaque optimistic-lock token for the administrator review form.
     * Private fields affect the hash but are never exposed in the token.
     */
    public function reviewVersion(): string
    {
        return hash('sha256', json_encode([
            $this->getKey(),
            $this->updated_at?->format('Y-m-d H:i:s.u'),
            $this->organiser_type,
            $this->verification_level,
            $this->status,
            $this->public_name,
            $this->public_contact_email,
            $this->public_contact_phone,
            $this->legal_name,
            $this->registration_number,
            $this->tax_identifier,
            $this->verified_at?->format('Y-m-d H:i:s.u'),
            $this->expires_at?->format('Y-m-d H:i:s.u'),
            $this->payout_name_verified_at?->format('Y-m-d H:i:s.u'),
        ], JSON_THROW_ON_ERROR));
    }

    public function badgeLabel(): ?string
    {
        if (! $this->isApproved()) {
            return null;
        }

        return $this->isBusinessVerified() ? 'Organisation verified' : 'Identity verified';
    }

    public function badgeDescription(): ?string
    {
        if (! $label = $this->badgeLabel()) {
            return null;
        }

        $date = $this->verified_at?->format('j M Y');
        $verified = $date ? "{$label} by Okesheni on {$date}." : "{$label} by Okesheni.";

        return $verified.' Verification does not guarantee the event, its quality or a refund.';
    }

    /**
     * A deliberately small, guest-safe representation. Review evidence is never exposed here.
     */
    public function publicSummary(): array
    {
        return [
            'organiser_type' => $this->organiser_type,
            'verification_level' => $this->isApproved() ? $this->verification_level : self::LEVEL_NONE,
            'verification_status' => $this->effectiveStatus(),
            'public_name' => $this->public_name,
            'public_contact_email' => $this->public_contact_email,
            'public_contact_phone' => $this->public_contact_phone,
            'badge_label' => $this->badgeLabel(),
            'badge_description' => $this->badgeDescription(),
            'verified_at' => $this->isApproved() ? $this->verified_at?->toAtomString() : null,
        ];
    }
}
