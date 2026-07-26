<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin object{customer_email: string, customer_name: ?string, order_count: int, total_spent: ?float, last_order_at: string}
 */
class CustomerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'customer_email' => $this->customer_email,
            'customer_name' => $this->customer_name,
            'order_count' => (int) $this->order_count,
            // SQL SUM() of an all-null column returns null, not 0 — treated
            // as "no resolvable total" rather than fabricating a $0 spend
            // figure (Plan §4.16's honesty caveat).
            'total_spent' => $this->total_spent === null ? null : (float) $this->total_spent,
            'last_order_at' => $this->last_order_at,
        ];
    }
}
