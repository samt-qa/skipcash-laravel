<?php

declare(strict_types=1);

namespace Samt\SkipCash\Exceptions;

/**
 * Thrown when credentials or configuration are missing or invalid.
 */
final class ConfigurationException extends SkipCashException
{
    public static function missing(string $key): self
    {
        return new self("SkipCash configuration value [{$key}] is missing. Set it in config/skipcash.php or your .env file.");
    }

    public static function invalidEnvironment(string $value): self
    {
        return new self("Invalid SkipCash environment [{$value}]. Expected \"sandbox\" or \"production\".");
    }
}
