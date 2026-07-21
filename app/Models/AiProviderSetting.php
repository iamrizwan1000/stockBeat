<?php

namespace App\Models;

use Database\Factories\AiProviderSettingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One row per AI provider (Plan §4.12/§8.7.9/§9). Whichever row has
 * `active = true` is what `AiProviderManager` resolves at request time —
 * admin flips the switch, the very next `/assistant/ask` call uses it, no
 * deploy. `api_key` is encrypted at rest and hidden from serialization,
 * same discipline as `store_connections.credentials`.
 *
 * @property int $id
 * @property string $provider
 * @property string|null $api_key
 * @property string|null $model
 * @property bool $active
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['provider', 'api_key', 'model', 'active', 'updated_by'])]
#[Hidden(['api_key'])]
class AiProviderSetting extends Model
{
    /** @use HasFactory<AiProviderSettingFactory> */
    use HasFactory;

    public const PROVIDER_OPENAI = 'openai';

    public const PROVIDER_GROQ = 'groq';

    public const PROVIDER_CLAUDE = 'claude';

    /**
     * @return array<int, string>
     */
    public static function providers(): array
    {
        return [self::PROVIDER_OPENAI, self::PROVIDER_GROQ, self::PROVIDER_CLAUDE];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
            'active' => 'boolean',
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
