<?php

namespace App\Models;

use Database\Factories\FeatureUsageEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A minimal, generic usage log powering the admin Dashboard's feature-
 * adoption metrics (added 2026-07-27) — logged from inside the Action that
 * actually performs the thing, same "log where it really happened"
 * discipline as `OrderEvent`.
 *
 * @property int $id
 * @property int $team_id
 * @property string $feature
 * @property Carbon $occurred_at
 */
#[Fillable(['team_id', 'feature', 'occurred_at'])]
class FeatureUsageEvent extends Model
{
    /** @use HasFactory<FeatureUsageEventFactory> */
    use HasFactory;

    public $timestamps = false;

    public const FEATURE_INVOICE_GENERATED = 'invoice_generated';

    public const FEATURE_PACKING_SLIP_GENERATED = 'packing_slip_generated';

    public const FEATURE_BULK_PACKING_SLIPS_GENERATED = 'bulk_packing_slips_generated';

    public const FEATURE_BULK_CANCEL_USED = 'bulk_cancel_used';

    public const FEATURE_BULK_TAG_USED = 'bulk_tag_used';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public static function log(Team $team, string $feature): void
    {
        static::query()->create([
            'team_id' => $team->id,
            'feature' => $feature,
            'occurred_at' => now(),
        ]);
    }
}
