<?php

namespace App\Models;

use Database\Factories\StoreConnectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $team_id
 * @property string $platform
 * @property string $name
 * @property array<string, mixed>|null $credentials
 * @property string $status
 * @property Carbon|null $last_sync_at
 * @property string|null $webhook_status
 * @property string|null $region
 * @property array<string, mixed>|null $settings
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['team_id', 'platform', 'name', 'credentials', 'status', 'region', 'settings', 'webhook_status', 'last_sync_at'])]
#[Hidden(['credentials'])]
class StoreConnection extends Model
{
    /** @use HasFactory<StoreConnectionFactory> */
    use HasFactory;

    public const PLATFORM_SHOPIFY = 'shopify';

    public const PLATFORM_WOO = 'woo';

    public const PLATFORM_EBAY = 'ebay';

    public const PLATFORM_ETSY = 'etsy';

    public const PLATFORM_AMAZON = 'amazon';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_NEEDS_REAUTH = 'needs_reauth';

    public const STATUS_DISCONNECTED = 'disconnected';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'settings' => 'array',
            'last_sync_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
