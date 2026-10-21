<?php

declare(strict_types=1);

namespace Samt\SkipCash\Client;

use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Samt\SkipCash\Credentials\CredentialsResolver;
use Samt\SkipCash\Enums\Environment;
use Samt\SkipCash\Exceptions\ApiException;
use Samt\SkipCash\Signing\SignatureFields;
use Samt\SkipCash\Signing\Signer;
use Throwable;

/**
 * Low-level HTTP transport. Signs requests, unwraps the SkipCash envelope,
 * and converts failures into ApiException. Returns the raw `resultObj`.
 *
 * Prefer the high-level Samt\SkipCash\SkipCash gateway in application code.
 */
class SkipCashClient
{
    /**
     * @param  array{sandbox: string, production: string}  $baseUrls
     * @param  array{timeout: int, connect_timeout: int, read_retries: int}  $http
     */
    public function __construct(
        private readonly Container $container,
        private readonly CredentialsResolver $credentials,
        private readonly Signer $signer,
        private readonly array $baseUrls,
        private readonly array $http,
    ) {}

    // ------------------------------------------------------------------ //
    //  Endpoints
    // ------------------------------------------------------------------ //

    /**
     * @param  array<string, mixed>  $body  Must already include Uid and KeyId.
     * @return array<string, mixed>
     */
    public function createPayment(array $body): array
    {
        return $this->signedPost('/api/v1/payments', SignatureFields::CREATE_PAYMENT, $body);
    }

    /**
     * @return array<string, mixed>
     */
    public function getPayment(string $paymentId): array
    {
        return $this->clientIdGet('/api/v1/payments/'.rawurlencode($paymentId));
    }

    /**
     * @param  array<string, mixed>  $body  Id, KeyId, optional Amount.
     * @return array<string, mixed>
     */
    public function refund(array $body): array
    {
        return $this->signedPost('/api/v1/payments/refund', SignatureFields::REFUND, $body);
    }

    /**
     * @param  array<string, mixed>  $body  Id (subscription), KeyId.
     * @return array<string, mixed>
     */
    public function cancelSubscription(array $body): array
    {
        return $this->signedPost('/api/v1/payments/subscription/cancel', SignatureFields::CANCEL_SUBSCRIPTION, $body);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function updateCard(array $body): array
    {
        return $this->signedPost('/api/v1/payments/update-card', SignatureFields::UPDATE_CARD, $body);
    }

    /**
     * @param  list<string>  $tokenIds
     * @return list<array<string, mixed>>
     */
    public function cardDetails(array $tokenIds): array
    {
        $path = '/api/v1/token/cardDetails/'.implode('|', array_map('rawurlencode', $tokenIds));

        /** @var list<array<string, mixed>> $result */
        $result = $this->clientIdGet($path);

        return $result;
    }

    // ------------------------------------------------------------------ //
    //  Environment
    // ------------------------------------------------------------------ //

    public function environment(): Environment
    {
        return $this->credentials->environment();
    }

    public function baseUrl(): string
    {
        return rtrim($this->baseUrls[$this->environment()->value], '/');
    }

    public function keyId(): string
    {
        return $this->credentials->credentials()->keyId;
    }

    public function webhookKey(): string
    {
        return $this->credentials->credentials()->webhookKey();
    }

    // ------------------------------------------------------------------ //
    //  Transport
    // ------------------------------------------------------------------ //

    /**
     * @param  list<string>  $signatureFields
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    protected function signedPost(string $path, array $signatureFields, array $body): array
    {
        $secret = $this->credentials->credentials()->keySecret;
        $signature = $this->signer->sign($signatureFields, $body, $secret);

        $response = $this->send(
            fn (PendingRequest $request) => $request
                ->withHeaders(['Authorization' => $signature])
                ->post($this->baseUrl().$path, $body),
        );

        return $this->unwrap($response);
    }

    /**
     * @return array<string, mixed>
     */
    protected function clientIdGet(string $path): array
    {
        $clientId = $this->credentials->credentials()->clientId;
        $retries = max(0, $this->http['read_retries']);

        $response = $this->send(
            fn (PendingRequest $request) => $request
                ->withHeaders(['Authorization' => $clientId])
                ->retry($retries + 1, 300, throw: false)
                ->get($this->baseUrl().$path),
        );

        return $this->unwrap($response);
    }

    /**
     * @param  callable(PendingRequest): Response  $send
     */
    private function send(callable $send): Response
    {
        // Resolved per call so Http::fake() applies even after this client was built.
        $request = $this->container->make(HttpFactory::class)
            ->acceptJson()
            ->asJson()
            ->timeout($this->http['timeout'])
            ->connectTimeout($this->http['connect_timeout'])
            ->withUserAgent('samt/skipcash-laravel');

        try {
            return $send($request);
        } catch (ConnectionException $e) {
            throw ApiException::transport($e);
        } catch (Throwable $e) {
            throw ApiException::transport($e);
        }
    }

    /**
     * Validate the standard SkipCash envelope and return `resultObj`.
     *
     * @return array<string, mixed>
     */
    private function unwrap(Response $response): array
    {
        $status = $response->status();
        $body = $response->json();

        if (! is_array($body)) {
            throw ApiException::malformed($status, $response->body());
        }

        $hasError = (bool) ($body['hasError'] ?? false)
            || (bool) ($body['hasValidationError'] ?? false)
            || (isset($body['returnCode']) && (int) $body['returnCode'] >= 400)
            || $status >= 400;

        if ($hasError) {
            throw ApiException::fromResponse($status, $body);
        }

        $result = $body['resultObj'] ?? null;

        if (! is_array($result)) {
            throw ApiException::malformed($status, $response->body());
        }

        return $result;
    }
}
