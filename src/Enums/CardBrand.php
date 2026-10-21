<?php

declare(strict_types=1);

namespace Samt\SkipCash\Enums;

/**
 * Card brand as returned by GET /token/cardDetails (`cardType`).
 */
enum CardBrand: int
{
    case Visa = 1;
    case Mastercard = 2;

    public function label(): string
    {
        return match ($this) {
            self::Visa => 'Visa',
            self::Mastercard => 'Mastercard',
        };
    }
}
