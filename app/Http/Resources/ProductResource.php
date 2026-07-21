<?php

namespace App\Http\Resources;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Product
 */
class ProductResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'connection_id' => $this->connection_id,
            'sku' => $this->sku,
            'title' => $this->title,
            'stock_quantity' => $this->stock_quantity,
            'cost_price' => $this->cost_price === null ? null : (float) $this->cost_price,
        ];
    }
}
