<?php

declare(strict_types=1);

namespace Samt\SkipCash\Enums;

/**
 * Payment status as reported by SkipCash (`statusId`).
 *
 * @see https://dev.skipcash.app/doc/ "Get Transaction Details"
 */
enum PaymentStatus: int
{
    case New = 0;
    case Pending = 1;
    case Paid = 2;
    case Canceled = 3;
    case Failed = 4;
    case Rejected = 5;
    case Refunded = 6;
    case PendingRefund = 7;
    case RefundFailed = 8;

    /**
     * The shopper has started paying (card entered, 3-D Secure in progress).
     * SkipCash resets it to New if the payment is not completed within 2 minutes.
     */
    case InProgress = 12;

    public function label(): string
    {
        return match ($this) {
            self::New => 'new',
            self::Pending => 'pending',
            self::Paid => 'paid',
            self::Canceled => 'canceled',
            self::Failed => 'failed',
            self::Rejected => 'rejected',
            self::Refunded => 'refunded',
            self::PendingRefund => 'pending refund',
            self::RefundFailed => 'refund failed',
            self::InProgress => 'in progress',
        };
    }

    /** The shopper's money was captured (or captured then refunded). */
    public function isSuccessful(): bool
    {
        return in_array($this, [self::Paid, self::Refunded, self::PendingRefund, self::RefundFailed], true);
    }

    /** No money moved and no further attempt is expected on this payment id. */
    public function isUnsuccessful(): bool
    {
        return in_array($this, [self::Canceled, self::Failed, self::Rejected], true);
    }

    /**
     * Backing values of every open status, for query builders.
     *
     * @return list<int>
     */
    public static function openValues(): array
    {
        return [self::New->value, self::Pending->value, self::InProgress->value];
    }

    /** The payment is still open and may transition. */
    public function isOpen(): bool
    {
        return in_array($this, [self::New, self::Pending, self::InProgress], true);
    }

    public function isRefundRelated(): bool
    {
        return in_array($this, [self::Refunded, self::PendingRefund, self::RefundFailed], true);
    }

    /**
     * Whether a transition from the current status to $next is allowed.
     *
     * Webhooks may arrive out of order; a paid payment must never regress to
     * failed/canceled. Refund states may only follow a successful capture.
     */
    public function canTransitionTo(self $next): bool
    {
        if ($this === $next) {
            return false;
        }

        return match ($this) {
            self::New, self::Pending, self::InProgress => true,
            self::Paid => $next->isRefundRelated(),
            self::PendingRefund => in_array($next, [self::Refunded, self::RefundFailed], true),
            self::RefundFailed => in_array($next, [self::PendingRefund, self::Refunded], true),
            // A further partial refund on an already (partially) refunded payment.
            self::Refunded => $next === self::PendingRefund,
            self::Canceled, self::Failed, self::Rejected => $next === self::Paid,
        };
    }
}
