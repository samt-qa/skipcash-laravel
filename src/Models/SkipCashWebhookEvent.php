<?php

declare(strict_types=1);

namespace Samt\SkipCash\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Inbox of verified webhook deliveries. Provides idempotency and an audit trail.
 *
 * @property int $id
 * @property string $payment_id
 * @property int $status_id
 * @property int|null $from_status_id
 * @property string|null $transaction_id
 * @property array<string, mixed> $payload
 * @property int $attempts
 * @property CarbonImmutable $received_at
 * @property CarbonImmutable|null $processed_at
 * @property CarbonImmutable|null $failed_at
 * @property string|null $error
 */
class SkipCashWebhookEvent extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'payload' => 'array',
        'attempts' => 'integer',
        'status_id' => 'integer',
        'from_status_id' => 'integer',
        'received_at' => 'immutable_datetime',
        'processed_at' => 'immutable_datetime',
        'failed_at' => 'immutable_datetime',
    ];

    public function getTable(): string
    {
        return (string) config('skipcash.database.webhook_events_table', 'skipcash_webhook_events');
    }

    public function getConnectionName(): ?string
    {
        return config('skipcash.database.connection') ?: parent::getConnectionName();
    }

    public function isProcessed(): bool
    {
        return $this->processed_at !== null;
    }
}
