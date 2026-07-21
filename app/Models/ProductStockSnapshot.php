<?php

namespace App\Models;

use Database\Factories\ProductStockSnapshotFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One point-in-time stock reading for a product (Plan §4.12 Phase B) —
 * unlike `products.stock_quantity` (overwritten every poll), this table is
 * append-only, so "how has my inventory trended" questions are actually
 * answerable. Written alongside every `Product::updateOrCreate()` stock
 * update (currently `PollWooProductsJob` — the only adapter with real
 * product polling today) whenever `stock_quantity` is non-null (managed
 * stock).
 *
 * @property int $id
 * @property int $product_id
 * @property int $stock_quantity
 * @property Carbon $recorded_at
 */
#[Fillable(['product_id', 'stock_quantity', 'recorded_at'])]
class ProductStockSnapshot extends Model
{
    /** @use HasFactory<ProductStockSnapshotFactory> */
    use HasFactory;

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'recorded_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
