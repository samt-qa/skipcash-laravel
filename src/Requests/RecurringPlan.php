<?php

declare(strict_types=1);

namespace Samt\SkipCash\Requests;

use DateTimeInterface;
use Samt\SkipCash\Enums\Interval;
use Samt\SkipCash\Exceptions\InvalidRequestException;
use Samt\SkipCash\Support\Amount;

/**
 * Recurring (subscription) options attached to a payment.
 *
 * Example — charge every month for one year:
 *   RecurringPlan::make('Gold', Interval::Month, startDate: $today, endDate: $nextYear)
 */
final class RecurringPlan
{
    private function __construct(
        public readonly string $planName,
        public readonly Interval $interval,
        public readonly int $frequency,
        public readonly string $startDate,
        public readonly string $endDate,
        public readonly int $allowedFailedAttempts,
        public readonly ?string $firstPaymentAmount,
    ) {
        if (trim($planName) === '') {
            throw InvalidRequestException::field('planName', 'is required');
        }

        if ($frequency < 1) {
            throw InvalidRequestException::field('frequency', 'must be at least 1');
        }

        if ($allowedFailedAttempts < 0) {
            throw InvalidRequestException::field('allowedFailedAttempts', 'must not be negative');
        }

        if ($endDate < $startDate) {
            throw InvalidRequestException::field('endDate', 'must not be before startDate');
        }
    }

    public static function make(
        string $planName,
        Interval $interval,
        DateTimeInterface|string $startDate,
        DateTimeInterface|string $endDate,
        int $frequency = 1,
        int $allowedFailedAttempts = 3,
        int|float|string|null $firstPaymentAmount = null,
    ): self {
        return new self(
            planName: $planName,
            interval: $interval,
            frequency: $frequency,
            startDate: self::date($startDate, 'startDate'),
            endDate: self::date($endDate, 'endDate'),
            allowedFailedAttempts: $allowedFailedAttempts,
            firstPaymentAmount: $firstPaymentAmount === null ? null : Amount::format($firstPaymentAmount),
        );
    }

    /**
     * Body fields (never part of the signature).
     *
     * @return array<string, string|bool>
     */
    public function toArray(): array
    {
        $fields = [
            'IsRecurring' => true,
            'PlanName' => $this->planName,
            'Frequency' => (string) $this->frequency,
            'Interval' => $this->interval->value,
            'StartDate' => $this->startDate,
            'EndDate' => $this->endDate,
            'AllowedFailedAttempts' => (string) $this->allowedFailedAttempts,
        ];

        if ($this->firstPaymentAmount !== null) {
            $fields['FirstPaymentAmount'] = $this->firstPaymentAmount;
        }

        return $fields;
    }

    private static function date(DateTimeInterface|string $value, string $field): string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            throw InvalidRequestException::field($field, 'must be in YYYY-MM-DD format');
        }

        return $value;
    }
}
