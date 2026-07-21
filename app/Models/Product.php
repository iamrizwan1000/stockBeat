<?php

namespace App\Models;

use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A per-connection product snapshot, polled from the platform (Plan §4.4's
 * `low_stock` trigger) — not a catalog/listing management feature, just
 * enough to know current stock. `cost_price` (Plan §4.12 Phase B) is the
 * one field here that's never polled from a platform — no adapter exposes
 * true cost-of-goods via its API, so it's always seller-entered
 * (`PUT /api/v1/products/{product}/cost-price`) and stays `null` until
 * set. Profit tools (`AssistantToolRegistry::getProfitSummary`) only ever
 * include items whose product has a real `cost_price` — never estimated.
 *
 * @property int $id
 * @property int $team_id
 * @property int $connection_id
 * @property string $external_id
 * @property string|null $sku
 * @property string $title
 * @property int|null $stock_quantity
 * @property float|null $cost_price
 * @property Carbon|null $low_stock_notified_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['team_id', 'connection_id', 'external_id', 'sku', 'title', 'stock_quantity', 'cost_price', 'low_stock_notified_at'])]
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cost_price' => 'decimal:2',
            'low_stock_notified_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * @return BelongsTo<StoreConnection, $this>
     */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(StoreConnection::class, 'connection_id');
    }

    /**
     * @return HasMany<ProductStockSnapshot, $this>
     */
    public function stockSnapshots(): HasMany
    {
        return $this->hasMany(ProductStockSnapshot::class);
    }
}
