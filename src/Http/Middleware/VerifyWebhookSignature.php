<?php

declare(strict_types=1);

namespace Samt\SkipCash\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Psr\Log\LoggerInterface;
use Samt\SkipCash\Exceptions\ConfigurationException;
use Samt\SkipCash\Exceptions\InvalidSignatureException;
use Samt\SkipCash\Webhooks\WebhookVerifier;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejects webhooks whose HMAC signature does not match the configured webhook key.
 */
final class VerifyWebhookSignature
{
    public function __construct(
        private readonly WebhookVerifier $verifier,
        private readonly LoggerInterface $logger,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var array<string, mixed> $body */
        $body = $request->json()->all();

        try {
            $this->verifier->verify($body, $request->header('Authorization'));
        } catch (InvalidSignatureException $e) {
            $this->logger->warning('SkipCash: webhook rejected.', [
                'reason' => $e->getMessage(),
                'ip' => $request->ip(),
                'payment_id' => $body['PaymentId'] ?? null,
            ]);

            return new JsonResponse(['error' => 'Invalid signature'], Response::HTTP_UNAUTHORIZED);
        } catch (ConfigurationException $e) {
            $this->logger->error('SkipCash: webhook key not configured.', ['reason' => $e->getMessage()]);

            return new JsonResponse(['error' => 'Webhook not configured'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return $next($request);
    }
}
