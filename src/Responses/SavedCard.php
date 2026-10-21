<?php

declare(strict_types=1);

namespace Samt\SkipCash\Responses;

use Samt\SkipCash\Enums\CardBrand;

/**
 * A tokenised card as returned by GET /token/cardDetails/{tokenIds}.
 */
final class SavedCard
{
    public function __construct(
        public readonly string $tokenId,
        public readonly string $lastFour,
        public readonly ?CardBrand $brand,
        public readonly ?string $expiry,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data, string $tokenId): self
    {
        $type = $data['cardType'] ?? null;

        return new self(
            tokenId: $tokenId,
            lastFour: (string) ($data['cardNumber'] ?? ''),
            brand: is_numeric($type) ? CardBrand::tryFrom((int) $type) : null,
            expiry: isset($data['cardExpiry']) ? (string) $data['cardExpiry'] : null,
        );
    }

    public function masked(): string
    {
        return '•••• '.$this->lastFour;
    }
}
