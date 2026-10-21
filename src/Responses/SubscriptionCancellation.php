<?php

declare(strict_types=1);

namespace Samt\SkipCash\Responses;

/**
 * Result of POST /api/v1/payments/subscription/cancel.
 */
final class SubscriptionCancellation
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly string $subscriptionId,
        public readonly bool $cancelled,
        public readonly ?string $message,
        public readonly array $raw,
    ) {}

    /**
     * @param  array<string, mixed>  $resultObj
     */
    public static function fromArray(array $resultObj, string $fallbackId): self
    {
        return new self(
            subscriptionId: (string) ($resultObj['subscriptionId'] ?? $fallbackId),
            cancelled: (bool) ($resultObj['isCancelled'] ?? false),
            message: isset($resultObj['message']) ? (string) $resultObj['message'] : null,
            raw: $resultObj,
        );
    }
}
