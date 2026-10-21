<?php

declare(strict_types=1);

namespace Samt\SkipCash\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Samt\SkipCash\Enums\PaymentStatus;
use Samt\SkipCash\Models\SkipCashPayment;
use Samt\SkipCash\Webhooks\WebhookPayload;

/**
 * Fired on every accepted status transition, before the specific event
 * (PaymentPaid, PaymentFailed, ...).
 */
final class PaymentStatusChanged
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public SkipCashPayment $payment,
        public PaymentStatus $from,
        public PaymentStatus $to,
        public ?WebhookPayload $webhook = null,
    ) {}
}
