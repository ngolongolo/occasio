<?php

namespace App\Services;

use App\Models\AdminAuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class AdminAuditLogger
{
    /**
     * Fields carrying evidence, credentials, payment coordinates or private notes
     * must never be duplicated into the searchable JSON snapshots.
     */
    private const SENSITIVE_KEYS = [
        'password', 'remember_token', 'token', 'secret', 'key', 'callback_token',
        'legal_name', 'registration_number', 'tax_identifier', 'review_notes',
        'payout_number', 'payment_number', 'request_response', 'callback_payload',
    ];

    public function record(
        User $actor,
        string $action,
        ?User $subject = null,
        ?Model $auditable = null,
        array $before = [],
        array $after = [],
        ?string $reason = null,
        ?Request $request = null,
        array $context = [],
    ): AdminAuditLog {
        return AdminAuditLog::create([
            'actor_id' => $actor->getKey(),
            'subject_user_id' => $subject?->getKey(),
            'auditable_type' => $auditable?->getMorphClass(),
            'auditable_id' => $auditable?->getKey(),
            'action' => $action,
            'reason' => filled($reason) ? trim($reason) : null,
            'before_state' => $this->sanitize($before),
            'after_state' => $this->sanitize($after),
            'context' => $this->sanitize($context),
            'ip_address' => $request?->ip(),
            'user_agent' => mb_substr((string) $request?->userAgent(), 0, 500) ?: null,
        ]);
    }

    private function sanitize(array $values): array
    {
        $safe = [];

        foreach ($values as $key => $value) {
            $normalizedKey = strtolower((string) $key);
            if ($this->isSensitive($normalizedKey)) {
                continue;
            }

            $safe[$key] = is_array($value) ? $this->sanitize($value) : $value;
        }

        return $safe;
    }

    private function isSensitive(string $key): bool
    {
        foreach (self::SENSITIVE_KEYS as $sensitive) {
            if ($key === $sensitive || str_contains($key, $sensitive)) {
                return true;
            }
        }

        return false;
    }
}
