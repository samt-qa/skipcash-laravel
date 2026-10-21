<?php

declare(strict_types=1);

namespace Samt\SkipCash\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Samt\SkipCash\Models\SkipCashPayment;
use Samt\SkipCash\Webhooks\WebhookPayload;

/**
 * Base class for payment lifecycle events.
 *
 * $webhook is null when the change was discovered by reconciliation
 * (GET /api/v1/payments/{id}) rather than by an incoming webhook.
 */
abstract class PaymentEvent
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public SkipCashPayment $payment,
        public ?WebhookPayload $webhook = null,
    ) {}
}
