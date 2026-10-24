<?php

namespace App\Services;

final class TanzanianPhoneNumber
{
    /**
     * Normalize a Tanzanian mobile number to E.164 (for example +255712345678).
     */
    public static function normalize(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        // Accept common human-friendly separators, but reject letters and
        // punctuation instead of silently removing arbitrary characters.
        if (! preg_match('/^\+?[0-9\s().-]+$/', $value)) {
            return null;
        }

        $compact = preg_replace('/[\s().-]+/', '', $value);

        if (str_starts_with($compact, '00')) {
            $compact = '+'.substr($compact, 2);
        }

        if (preg_match('/^0([67]\d{8})$/', $compact, $matches)) {
            return '+255'.$matches[1];
        }

        if (preg_match('/^([67]\d{8})$/', $compact, $matches)) {
            return '+255'.$matches[1];
        }

        if (preg_match('/^\+?255([67]\d{8})$/', $compact, $matches)) {
            return '+255'.$matches[1];
        }

        return null;
    }
}
