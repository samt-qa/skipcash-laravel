<?php

declare(strict_types=1);

namespace Samt\SkipCash\Exceptions;

/**
 * Thrown when an incoming webhook signature does not match the webhook key.
 */
final class InvalidSignatureException extends SkipCashException
{
    public static function missingHeader(): self
    {
        return new self('SkipCash webhook rejected: missing Authorization header.');
    }

    public static function mismatch(): self
    {
        return new self('SkipCash webhook rejected: signature mismatch.');
    }
}
