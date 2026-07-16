<?php

namespace App\Models;

use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A per-connection product snapshot, polled from the platform (Plan §4.4's
 * `low_stock` trigger) — not a catalog/listing management feature, just
 * enough to know current stock.
 *
 * @property int $id
 * @property int $team_id
 * @property int $connection_id
 * @property string $external_id
 * @property string|null $sku
 * @property string $title
 * @property int|null $stock_quantity
 * @property Carbon|null $low_stock_notified_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['team_id', 'connection_id', 'external_id', 'sku', 'title', 'stock_quantity', 'low_stock_notified_at'])]
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
}
