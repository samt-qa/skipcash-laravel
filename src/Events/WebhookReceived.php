<?php

declare(strict_types=1);

namespace Samt\SkipCash\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Samt\SkipCash\Webhooks\WebhookPayload;

/**
 * Fired for every webhook whose signature verified, before any processing.
 */
final class WebhookReceived
{
    use Dispatchable;

    public function __construct(public readonly WebhookPayload $payload) {}
}
