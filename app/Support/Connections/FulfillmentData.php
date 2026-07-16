<?php

namespace App\Support\Connections;

final readonly class FulfillmentData
{
    public function __construct(
        public string $trackingNumber,
        public ?string $carrier = null,
    ) {}
}
