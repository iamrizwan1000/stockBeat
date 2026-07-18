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
 * @property string|null $fingerprint
 * @property string $status
 * @property Carbon|null $paused_at
 * @property Carbon|null $last_sync_at
 * @property Carbon|null $last_message_sync_at
 * @property string|null $webhook_status
 * @property string|null $region
 * @property array<string, mixed>|null $settings
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['team_id', 'platform', 'name', 'credentials', 'fingerprint', 'status', 'paused_at', 'region', 'settings', 'webhook_status', 'last_sync_at', 'last_message_sync_at'])]
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

    /**
     * TikTok Shop (Plan §7.6) — the OAuth authorization-code flow +
     * webhooks channel adapter, see `TikTokAdapter`.
     */
    public const PLATFORM_TIKTOK = 'tiktok';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_NEEDS_REAUTH = 'needs_reauth';

    public const STATUS_DISCONNECTED = 'disconnected';

    /**
     * Auto-paused by downgrade freeze (Plan §6.4) — distinct from
     * `disconnected` (the merchant removed it) or `needs_reauth` (a token
     * expired). Excluded from polling; restored, not deleted, on upgrade.
     */
    public const STATUS_PAUSED = 'paused';

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
            'last_message_sync_at' => 'datetime',
            'paused_at' => 'datetime',
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
