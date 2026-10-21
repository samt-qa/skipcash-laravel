<?php

declare(strict_types=1);

namespace Samt\SkipCash\Responses;

/**
 * Result of POST /api/v1/payments/refund.
 *
 * Refunds are asynchronous (usually settled within a day). Track completion
 * via webhooks, or by re-reading the original payment's `refundStatus`.
 */
final class Refund
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly ?string $refundId,
        public readonly string $paymentId,
        public readonly ?string $amount,
        public readonly array $raw,
    ) {}

    /**
     * @param  array<string, mixed>  $resultObj
     */
    public static function fromArray(array $resultObj, string $paymentId, ?string $amount): self
    {
        $refundId = $resultObj['refundId'] ?? $resultObj['id'] ?? null;

        return new self(
            refundId: $refundId === null ? null : (string) $refundId,
            paymentId: $paymentId,
            amount: $amount,
            raw: $resultObj,
        );
    }
}
