<?php

declare(strict_types=1);

namespace Samt\SkipCash\Http\Controllers;

use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher as EventDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Psr\Log\LoggerInterface;
use Samt\SkipCash\Events\WebhookReceived;
use Samt\SkipCash\Exceptions\InvalidRequestException;
use Samt\SkipCash\Jobs\ProcessWebhook;
use Samt\SkipCash\Webhooks\WebhookPayload;
use Samt\SkipCash\Webhooks\WebhookProcessor;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Receives verified SkipCash webhooks.
 *
 * SkipCash waits 10 seconds for a 200 and retries only three times (immediately,
 * +1h, +1d). With `skipcash.webhooks.queue` enabled the request is acknowledged
 * at once and processed on a queue, where the application's own retry policy
 * applies.
 */
final class WebhookController
{
    public function __construct(
        private readonly WebhookProcessor $processor,
        private readonly BusDispatcher $bus,
        private readonly EventDispatcher $events,
        private readonly Repository $config,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        try {
            /** @var array<string, mixed> $body */
            $body = $request->json()->all();
            $payload = WebhookPayload::fromArray($body);
        } catch (InvalidRequestException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        $this->events->dispatch(new WebhookReceived($payload));

        if ((bool) $this->config->get('skipcash.webhooks.queue', true)) {
            $this->bus->dispatch(ProcessWebhook::make($payload, $this->config));

            // SkipCash only treats HTTP 200 as delivered.
            return new JsonResponse(['received' => true, 'queued' => true]);
        }

        try {
            $outcome = $this->processor->process($payload);
        } catch (Throwable $e) {
            $this->logger->error('SkipCash: webhook processing failed.', [
                'payment_id' => $payload->paymentId,
                'exception' => $e,
            ]);

            // Non-200 makes SkipCash retry the delivery.
            return new JsonResponse(['error' => 'Processing failed'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return new JsonResponse(['received' => true, 'outcome' => $outcome->value]);
    }
}
