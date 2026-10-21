<?php

declare(strict_types=1);

namespace Samt\SkipCash\Credentials;

use Samt\SkipCash\Enums\Environment;

/**
 * Supplies credentials and environment at call time.
 *
 * Bind your own implementation to support multi-tenant applications where
 * each tenant owns a SkipCash merchant account:
 *
 *     $this->app->bind(CredentialsResolver::class, TenantCredentialsResolver::class);
 */
interface CredentialsResolver
{
    public function credentials(): Credentials;

    public function environment(): Environment;
}
