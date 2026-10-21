<?php

declare(strict_types=1);

namespace Samt\SkipCash\Credentials;

use Illuminate\Contracts\Config\Repository;
use Samt\SkipCash\Enums\Environment;
use Samt\SkipCash\Exceptions\ConfigurationException;

/**
 * Default resolver: reads credentials from config/skipcash.php.
 */
final class ConfigCredentialsResolver implements CredentialsResolver
{
    public function __construct(private readonly Repository $config) {}

    public function credentials(): Credentials
    {
        /** @var array{client_id?: ?string, key_id?: ?string, key_secret?: ?string, webhook_key?: ?string} $values */
        $values = (array) $this->config->get('skipcash.credentials', []);

        return Credentials::fromArray($values);
    }

    public function environment(): Environment
    {
        $value = (string) $this->config->get('skipcash.environment', 'sandbox');

        return Environment::tryFrom(strtolower($value)) ?? throw ConfigurationException::invalidEnvironment($value);
    }
}
