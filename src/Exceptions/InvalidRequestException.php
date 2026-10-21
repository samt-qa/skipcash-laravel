<?php

declare(strict_types=1);

namespace Samt\SkipCash\Exceptions;

/**
 * Thrown before any HTTP call when request data violates SkipCash constraints.
 */
final class InvalidRequestException extends SkipCashException
{
    public static function field(string $field, string $reason): self
    {
        return new self("Invalid SkipCash request: [{$field}] {$reason}.");
    }
}
