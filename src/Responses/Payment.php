<?php

declare(strict_types=1);

namespace Samt\SkipCash\Responses;

use DateTimeImmutable;
use DateTimeZone;
use Samt\SkipCash\Enums\PaymentStatus;

/**
 * A payment as returned by SkipCash (`resultObj` of create / get endpoints).
 */
final class Payment
{
    private const NIL_UUID = '00000000-0000-0000-0000-000000000000';

    /**
     * @param  array<int, string|null>  $custom  Index 1..10
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly string $id,
        public readonly PaymentStatus $status,
        public readonly string $amount,
        public readonly string $currency,
        public readonly ?string $payUrl,
        public readonly ?string $transactionId,
        public readonly ?DateTimeImmutable $createdAt,
        public readonly ?DateTimeImmutable $finishedAt,
        public readonly ?string $visaId,
        public readonly ?string $tokenId,
        public readonly ?string $cardType,
        public readonly ?string $cardNumber,
        public readonly ?string $refundId,
        public readonly ?PaymentStatus $refundStatus,
        public readonly ?string $recurringSubscriptionId,
        public readonly ?string $firstPaymentAmount,
        public readonly array $custom,
        public readonly array $raw,
    ) {}

    /**
     * @param  array<string, mixed>  $data  The `resultObj` payload.
     */
    public static function fromArray(array $data): self
    {
        $custom = [];
        for ($i = 1; $i <= 10; $i++) {
            $custom[$i] = self::nullableString($data["custom{$i}"] ?? null);
        }

        $subscription = self::nullableString($data['recurringSubscriptionId'] ?? null);
        $refundStatusId = $data['refundStatusId'] ?? null;

        return new self(
            id: (string) ($data['id'] ?? ''),
            status: PaymentStatus::from((int) ($data['statusId'] ?? 0)),
            amount: (string) ($data['amount'] ?? '0.00'),
            currency: (string) ($data['currency'] ?? 'QAR'),
            payUrl: self::nullableString($data['payUrl'] ?? null),
            transactionId: self::nullableString($data['transactionId'] ?? null),
            createdAt: self::date($data['created'] ?? null),
            finishedAt: self::date($data['finishedDate'] ?? null),
            visaId: self::nullableString($data['visaId'] ?? null),
            tokenId: self::token($data['tokenId'] ?? null),
            cardType: self::nullableString($data['cardType'] ?? null),
            cardNumber: self::nullableString($data['cardNumber'] ?? null),
            refundId: self::nullableString($data['refundId'] ?? null),
            refundStatus: is_numeric($refundStatusId) ? PaymentStatus::tryFrom((int) $refundStatusId) : null,
            recurringSubscriptionId: $subscription === self::NIL_UUID ? null : $subscription,
            firstPaymentAmount: self::nullableString($data['firstPaymentAmount'] ?? null),
            custom: $custom,
            raw: $data,
        );
    }

    public function isPaid(): bool
    {
        return $this->status === PaymentStatus::Paid;
    }

    public function isRecurring(): bool
    {
        return $this->recurringSubscriptionId !== null;
    }

    public function hasToken(): bool
    {
        return $this->tokenId !== null;
    }

    /**
     * @param  int<1, 10>  $slot
     */
    public function custom(int $slot): ?string
    {
        return $this->custom[$slot] ?? null;
    }

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /** SkipCash sends "NA" or "" when no token was issued. */
    private static function token(mixed $value): ?string
    {
        $value = self::nullableString($value);

        return $value === null || strtoupper($value) === 'NA' ? null : $value;
    }

    private static function date(mixed $value): ?DateTimeImmutable
    {
        $value = self::nullableString($value);

        if ($value === null) {
            return null;
        }

        try {
            return new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (\Throwable) {
            return null;
        }
    }
}
