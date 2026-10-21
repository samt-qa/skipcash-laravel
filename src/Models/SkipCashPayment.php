<?php

declare(strict_types=1);

namespace Samt\SkipCash\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Samt\SkipCash\Enums\PaymentStatus;

/**
 * Local mirror of a SkipCash payment.
 *
 * @property int $id
 * @property string $payment_id
 * @property string|null $transaction_id
 * @property PaymentStatus $status
 * @property string $amount
 * @property string $currency
 * @property string $environment
 * @property string|null $pay_url
 * @property string|null $visa_id
 * @property string|null $token_id
 * @property string|null $card_type
 * @property string|null $card_number
 * @property string|null $recurring_subscription_id
 * @property string|null $refund_id
 * @property PaymentStatus|null $refund_status
 * @property array<int|string, string|null>|null $custom
 * @property string|null $payable_type
 * @property int|string|null $payable_id
 * @property CarbonImmutable|null $paid_at
 * @property CarbonImmutable|null $finished_at
 * @property CarbonImmutable|null $last_webhook_at
 * @property CarbonImmutable|null $last_synced_at
 * @property array<string, mixed>|null $raw
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
class SkipCashPayment extends Model
{
    protected $guarded = [];

    protected $casts = [
        'status' => PaymentStatus::class,
        'refund_status' => PaymentStatus::class,
        'custom' => 'array',
        'raw' => 'array',
        'paid_at' => 'immutable_datetime',
        'finished_at' => 'immutable_datetime',
        'last_webhook_at' => 'immutable_datetime',
        'last_synced_at' => 'immutable_datetime',
    ];

    public function getTable(): string
    {
        return (string) config('skipcash.database.payments_table', 'skipcash_payments');
    }

    public function getConnectionName(): ?string
    {
        return config('skipcash.database.connection') ?: parent::getConnectionName();
    }

    /** The order / invoice / booking this payment belongs to (optional). */
    public function payable(): MorphTo
    {
        return $this->morphTo();
    }

    public function isPaid(): bool
    {
        return $this->status === PaymentStatus::Paid;
    }

    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [PaymentStatus::New->value, PaymentStatus::Pending->value]);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForTransaction(Builder $query, string $transactionId): Builder
    {
        return $query->where('transaction_id', $transactionId);
    }
}
