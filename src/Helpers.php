<?php

declare(strict_types=1);

namespace VolunteerLine;

use DateTimeImmutable;
use DateTimeZone;

final class Helpers
{
    public static function digitsOnly(?string $value): string
    {
        if ($value === null) {
            return '';
        }

        return preg_replace('/\D+/', '', $value) ?? '';
    }

    /**
     * Format digits for digit-by-digit TTS readback (e.g. "1234" -> "1, 2, 3, 4").
     */
    public static function digitsForSpeech(string $digits): string
    {
        $clean = self::digitsOnly($digits);
        if ($clean === '') {
            return '';
        }

        return implode(', ', str_split($clean));
    }

    /**
     * Format a phone number / Caller ID in 10-digit US/CA format when possible.
     */
    public static function formatPhone10(?string $raw): string
    {
        $trimmed = trim((string) $raw);
        if ($trimmed === '') {
            return 'Unavailable';
        }

        $digits = self::digitsOnly($trimmed);
        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            return substr($digits, 1);
        }

        if (strlen($digits) === 10) {
            return $digits;
        }

        return $digits !== '' ? $digits : $trimmed;
    }

    public static function formatEasternNow(string $timezone = 'America/New_York'): string
    {
        $dt = new DateTimeImmutable('now', new DateTimeZone($timezone));

        return $dt->format('Y-m-d g:i:s A T');
    }

    public static function sanitizeToken(string $token): string
    {
        return preg_replace('/[^a-zA-Z0-9_-]/', '', $token) ?? '';
    }

    public static function generateId(int $bytes = 12): string
    {
        return bin2hex(random_bytes($bytes));
    }

    public static function signRecordingId(string $recId, string $secret): string
    {
        return hash_hmac('sha256', $recId, $secret);
    }

    public static function verifyRecordingToken(string $recId, string $token, string $secret): bool
    {
        if ($recId === '' || $token === '' || $secret === '') {
            return false;
        }

        $expected = self::signRecordingId($recId, $secret);

        return hash_equals($expected, $token);
    }

    public static function buildUrl(string $baseUrl, string $relativePath): string
    {
        return rtrim($baseUrl, '/') . '/' . ltrim($relativePath, '/');
    }

    /**
     * @param string|array<int, string> $value
     * @return array<int, string>
     */
    public static function normalizeEmailList(string|array $value): array
    {
        $items = is_array($value) ? $value : explode(',', $value);
        $emails = [];
        foreach ($items as $item) {
            $trimmed = trim((string) $item);
            if ($trimmed !== '') {
                $emails[] = $trimmed;
            }
        }

        return $emails;
    }

    public static function requestParam(string $key, string $default = ''): string
    {
        if (isset($_POST[$key])) {
            return is_scalar($_POST[$key]) ? (string) $_POST[$key] : $default;
        }
        if (isset($_GET[$key])) {
            return is_scalar($_GET[$key]) ? (string) $_GET[$key] : $default;
        }
        if (isset($_REQUEST[$key])) {
            return is_scalar($_REQUEST[$key]) ? (string) $_REQUEST[$key] : $default;
        }

        return $default;
    }
}
