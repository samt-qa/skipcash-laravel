<?php

declare(strict_types=1);

namespace Samt\SkipCash\Webhooks;

use Samt\SkipCash\Credentials\CredentialsResolver;
use Samt\SkipCash\Exceptions\InvalidSignatureException;
use Samt\SkipCash\Signing\SignatureFields;
use Samt\SkipCash\Signing\Signer;

/**
 * Verifies the Authorization header of an incoming webhook.
 */
final class WebhookVerifier
{
    public function __construct(
        private readonly Signer $signer,
        private readonly CredentialsResolver $credentials,
    ) {}

    /**
     * @param  array<string, mixed>  $body  The decoded JSON body, keys as received.
     *
     * @throws InvalidSignatureException
     */
    public function verify(array $body, ?string $authorizationHeader): void
    {
        if ($authorizationHeader === null || trim($authorizationHeader) === '') {
            throw InvalidSignatureException::missingHeader();
        }

        $key = $this->credentials->credentials()->webhookKey();

        if (! $this->signer->verify(SignatureFields::WEBHOOK, $body, $key, $authorizationHeader)) {
            throw InvalidSignatureException::mismatch();
        }
    }

    /**
     * @param  array<string, mixed>  $body
     */
    public function isValid(array $body, ?string $authorizationHeader): bool
    {
        try {
            $this->verify($body, $authorizationHeader);

            return true;
        } catch (InvalidSignatureException) {
            return false;
        }
    }
}
