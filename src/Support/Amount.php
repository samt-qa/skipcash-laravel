<?php

declare(strict_types=1);

namespace Samt\SkipCash\Support;

use Samt\SkipCash\Exceptions\InvalidRequestException;

/**
 * SkipCash amounts are strings with a dot separator and at most two decimals.
 */
final class Amount
{
    /**
     * @param  int|float|string  $value  Major units (QAR). Integers are read as whole riyals.
     */
    public static function format(int|float|string $value): string
    {
        if (is_string($value)) {
            $value = trim($value);

            if (! is_numeric($value)) {
                throw InvalidRequestException::field('Amount', 'must be numeric');
            }
        }

        $float = (float) $value;

        if ($float <= 0) {
            throw InvalidRequestException::field('Amount', 'must be greater than zero');
        }

        return number_format(round($float, 2), 2, '.', '');
    }

    /** Compare two amounts as money, tolerant of "100" vs "100.00". */
    public static function equals(int|float|string $a, int|float|string $b): bool
    {
        return self::format($a) === self::format($b);
    }
}
