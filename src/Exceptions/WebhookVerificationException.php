<?php

declare(strict_types=1);

namespace Samt\SkipCash\Exceptions;

/**
 * Thrown when a "paid" webhook could not be confirmed against the SkipCash API
 * (status or amount mismatch). The delivery is left unprocessed so it can be
 * retried, and nothing is marked as paid.
 */
final class WebhookVerificationException extends SkipCashException
{
    public static function statusMismatch(string $paymentId, string $remoteStatus): self
    {
        return new self("SkipCash webhook for payment [{$paymentId}] claims \"paid\" but the API reports \"{$remoteStatus}\".");
    }

    public static function amountMismatch(string $paymentId, string $expected, string $actual): self
    {
        return new self("SkipCash webhook for payment [{$paymentId}] reports amount {$actual} but the API reports {$expected}.");
    }
}
