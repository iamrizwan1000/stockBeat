<?php

namespace App\Support\Connections;

final readonly class RefundData
{
    /**
     * @param  float|null  $amount  null means a full refund of the order total.
     */
    public function __construct(
        public ?float $amount = null,
        public ?string $reason = null,
    ) {}
}
