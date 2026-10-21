<?php

declare(strict_types=1);

namespace Samt\SkipCash\Enums;

/**
 * Recurring payment interval unit.
 */
enum Interval: string
{
    case Day = 'day';
    case Week = 'week';
    case Month = 'month';
    case Year = 'year';
}
