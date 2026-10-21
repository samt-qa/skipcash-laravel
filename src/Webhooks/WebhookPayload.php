<?php

declare(strict_types=1);

namespace Samt\SkipCash\Webhooks;

use Samt\SkipCash\Enums\PaymentStatus;
use Samt\SkipCash\Exceptions\InvalidRequestException;

/**
 * Immutable, typed view of a SkipCash webhook body.
 *
 * Field names on the wire are PascalCase (PaymentId, StatusId, VisaId, ...)
 * and the masked card field is misspelt "CardNubmer" by SkipCash; both
 * spellings are accepted.
 */
final class WebhookPayload
{
    /**
     * @param  array<int, string|null>  $custom  Index 1..10
     * @param  array<string, mixed>  $raw  The body exactly as received.
     */
    public function __construct(
        public readonly string $paymentId,
        public readonly string $amount,
        public readonly PaymentStatus $status,
        public readonly ?string $transactionId,
        public readonly ?string $visaId,
        public readonly ?string $tokenId,
        public readonly ?string $cardType,
        public readonly ?string $cardNumber,
        public readonly ?string $recurringSubscriptionId,
        public readonly array $custom,
        public readonly array $raw,
    ) {}

    /**
     * @param  array<string, mixed>  $body
     */
    public static function fromArray(array $body): self
    {
        $paymentId = self::string($body['PaymentId'] ?? null);
        $statusId = $body['StatusId'] ?? null;

        if ($paymentId === null) {
            throw InvalidRequestException::field('PaymentId', 'is missing from webhook body');
        }

        if (! is_numeric($statusId) || PaymentStatus::tryFrom((int) $statusId) === null) {
            throw InvalidRequestException::field('StatusId', 'is missing or unknown in webhook body');
        }

        $custom = [];
        for ($i = 1; $i <= 10; $i++) {
            $custom[$i] = self::string($body["Custom{$i}"] ?? null);
        }

        $subscription = self::string($body['RecurringSubscriptionId'] ?? null);
        $token = self::string($body['TokenId'] ?? null);

        return new self(
            paymentId: $paymentId,
            amount: (string) ($body['Amount'] ?? '0'),
            status: PaymentStatus::from((int) $statusId),
            transactionId: self::string($body['TransactionId'] ?? null),
            visaId: self::string($body['VisaId'] ?? null),
            tokenId: $token !== null && strtoupper($token) === 'NA' ? null : $token,
            cardType: self::string($body['CardType'] ?? null),
            cardNumber: self::string($body['CardNumber'] ?? $body['CardNubmer'] ?? null),
            recurringSubscriptionId: $subscription === '00000000-0000-0000-0000-000000000000' ? null : $subscription,
            custom: $custom,
            raw: $body,
        );
    }

    /**
     * @param  int<1, 10>  $slot
     */
    public function custom(int $slot): ?string
    {
        return $this->custom[$slot] ?? null;
    }

    public function isPaid(): bool
    {
        return $this->status === PaymentStatus::Paid;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->raw;
    }

    private static function string(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
