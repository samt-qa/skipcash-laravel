<?php

declare(strict_types=1);

namespace Samt\SkipCash\Support;

use Samt\SkipCash\Exceptions\InvalidRequestException;

/**
 * Normalises shopper phone numbers to the format SkipCash accepts.
 *
 * Rules from the documentation:
 *  - Qatari numbers may be sent with or without a country code.
 *  - Non-Qatari numbers MUST include a country code prefixed with "+" (never "00").
 *  - Maximum length is 15 characters.
 */
final class Phone
{
    public static function normalize(string $raw, string $localCountryCode = '+974'): string
    {
        $value = preg_replace('/[\s\-().]/', '', trim($raw)) ?? '';

        if (str_starts_with($value, '00')) {
            $value = '+'.substr($value, 2);
        }

        if ($value === '' || ! preg_match('/^\+?\d+$/', $value)) {
            throw InvalidRequestException::field('Phone', 'must contain digits only, optionally prefixed with "+"');
        }

        // Bare 8-digit numbers are Qatari local numbers.
        if (! str_starts_with($value, '+') && strlen($value) === 8) {
            $value = $localCountryCode.$value;
        }

        // Local country code typed without "+", e.g. "97455123456".
        $localDigits = ltrim($localCountryCode, '+');
        if (! str_starts_with($value, '+') && str_starts_with($value, $localDigits) && strlen($value) === strlen($localDigits) + 8) {
            $value = '+'.$value;
        }

        if (! str_starts_with($value, '+')) {
            throw InvalidRequestException::field('Phone', 'non-Qatari numbers must include a "+" country code');
        }

        if (strlen($value) > 15) {
            throw InvalidRequestException::field('Phone', 'must not exceed 15 characters');
        }

        return $value;
    }
}
