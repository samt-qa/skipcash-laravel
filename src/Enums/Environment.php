<?php

declare(strict_types=1);

namespace Samt\SkipCash\Enums;

enum Environment: string
{
    case Sandbox = 'sandbox';
    case Production = 'production';

    public function isProduction(): bool
    {
        return $this === self::Production;
    }
}
