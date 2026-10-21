<?php

declare(strict_types=1);

namespace Samt\SkipCash\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Samt\SkipCash\Webhooks\WebhookPayload;
use Samt\SkipCash\Webhooks\WebhookProcessor;

/**
 * Queued processing of one webhook delivery.
 */
final class ProcessWebhook implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 5;

    /** @var list<int> Seconds between attempts: 30s, 2m, 10m, 1h. */
    public array $backoff = [30, 120, 600, 3600];

    public function __construct(public readonly WebhookPayload $payload) {}

    public static function make(WebhookPayload $payload, Repository $config): self
    {
        $job = new self($payload);

        $queue = $config->get('skipcash.webhooks.queue_name');
        $connection = $config->get('skipcash.webhooks.queue_connection');

        if (is_string($queue) && $queue !== '') {
            $job->onQueue($queue);
        }

        if (is_string($connection) && $connection !== '') {
            $job->onConnection($connection);
        }

        return $job;
    }

    public function handle(WebhookProcessor $processor): void
    {
        $processor->process($this->payload);
    }

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        return ['skipcash', 'payment:'.$this->payload->paymentId];
    }
}
