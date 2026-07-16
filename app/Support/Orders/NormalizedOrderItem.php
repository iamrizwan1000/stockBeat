<?php

namespace App\Support\Orders;

final readonly class NormalizedOrderItem
{
    public function __construct(
        public ?string $externalId,
        public ?string $sku,
        public string $title,
        public ?string $imageUrl,
        public int $qty,
        public float $price,
    ) {}
}
