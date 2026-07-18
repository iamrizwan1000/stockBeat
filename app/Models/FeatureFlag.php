<?php

namespace App\Models;

use App\Actions\FeatureFlags\IsFeatureEnabledForTeamAction;
use Database\Factories\FeatureFlagFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Plan §8.7.3 / §9: admin-editable feature flags with percentage-based
 * rollout, evaluated per-team by {@see IsFeatureEnabledForTeamAction}
 * and exposed to the mobile app via `/me`.
 *
 * @property int $id
 * @property string $key
 * @property string $name
 * @property string|null $description
 * @property bool $enabled
 * @property int $rollout_percentage
 * @property array<int, int>|null $enabled_for_team_ids
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['key', 'name', 'description', 'enabled', 'rollout_percentage', 'enabled_for_team_ids', 'updated_by'])]
class FeatureFlag extends Model
{
    /** @use HasFactory<FeatureFlagFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'rollout_percentage' => 'integer',
            'enabled_for_team_ids' => 'array',
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
