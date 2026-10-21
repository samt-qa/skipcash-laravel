<?php

declare(strict_types=1);

namespace Samt\SkipCash\Credentials;

use Samt\SkipCash\Exceptions\ConfigurationException;
use SensitiveParameter;

/**
 * Immutable set of merchant credentials for one SkipCash environment.
 */
final class Credentials
{
    public function __construct(
        public readonly string $clientId,
        public readonly string $keyId,
        #[SensitiveParameter] public readonly string $keySecret,
        #[SensitiveParameter] public readonly ?string $webhookKey = null,
    ) {
        foreach (['clientId', 'keyId', 'keySecret'] as $field) {
            if ($this->{$field} === '') {
                throw ConfigurationException::missing('credentials.'.self::snake($field));
            }
        }
    }

    /**
     * @param  array{client_id?: ?string, key_id?: ?string, key_secret?: ?string, webhook_key?: ?string}  $config
     */
    public static function fromArray(array $config): self
    {
        return new self(
            clientId: (string) ($config['client_id'] ?? ''),
            keyId: (string) ($config['key_id'] ?? ''),
            keySecret: (string) ($config['key_secret'] ?? ''),
            webhookKey: isset($config['webhook_key']) && $config['webhook_key'] !== '' ? (string) $config['webhook_key'] : null,
        );
    }

    /**
     * @throws ConfigurationException when no webhook key is configured
     */
    public function webhookKey(): string
    {
        return $this->webhookKey ?? throw ConfigurationException::missing('credentials.webhook_key');
    }

    /**
     * Prevent secrets from leaking into logs and dumps.
     *
     * @return array<string, string|null>
     */
    public function __debugInfo(): array
    {
        return [
            'clientId' => $this->clientId,
            'keyId' => $this->keyId,
            'keySecret' => '***',
            'webhookKey' => $this->webhookKey === null ? null : '***',
        ];
    }

    private static function snake(string $camel): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $camel));
    }
}
