<?php

namespace App\Models;

use Database\Factories\AppConfigFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Remote app config (Plan §8.7.7/§9): minimum supported app version,
 * maintenance-mode banner, and any other admin-editable key the mobile app
 * should be able to read without a release. Key-value on purpose — matches
 * §9's `app_config (key, value JSON, updated_by)` exactly; no mobile-facing
 * `GET /config` endpoint exists yet to serve these (out of scope for this
 * pass), so today this is admin-side only.
 *
 * @property int $id
 * @property string $key
 * @property mixed $value
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['key', 'value', 'updated_by'])]
class AppConfig extends Model
{
    /** @use HasFactory<AppConfigFactory> */
    use HasFactory;

    public const KEY_MIN_VERSION = 'min_version';

    public const KEY_MAINTENANCE_MODE = 'maintenance_mode';

    public const KEY_MAINTENANCE_BANNER = 'maintenance_banner';

    protected $table = 'app_config';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value' => 'json',
        ];
    }

    /**
     * @return BelongsTo<AdminUser, $this>
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'updated_by');
    }
}
