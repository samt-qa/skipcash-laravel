<?php

declare(strict_types=1);

namespace Samt\SkipCash\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Samt\SkipCash\Webhooks\WebhookPayload;

/**
 * A verified webhook referenced a payment and transaction this application
 * never created (for example a Payment Link made in the Merchant Portal).
 * A local record is created so the payment is not lost.
 */
final class UnknownPaymentReceived
{
    use Dispatchable;

    public function __construct(public readonly WebhookPayload $payload) {}
}
