<?php

namespace App\Models;

use Database\Factories\SegmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A reusable, saved audience definition for broadcasts (Plan §8.7.5). `filters`
 * shape: {plan?, platform?, inactive_days_gte?, trial_ending_within_days?,
 * marketing_opt_in?} — see `ResolveSegmentAudienceAction`. A `country` filter
 * from the spec isn't included: no country field is tracked on `users` yet
 * (same honest gap as `ListCustomersAction`).
 *
 * @property int $id
 * @property string $name
 * @property array<string, mixed>|null $filters
 * @property int $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'filters', 'created_by'])]
class Segment extends Model
{
    /** @use HasFactory<SegmentFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'filters' => 'array',
        ];
    }

    /**
     * @return BelongsTo<AdminUser, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'created_by');
    }

    /**
     * @return HasMany<Broadcast, $this>
     */
    public function broadcasts(): HasMany
    {
        return $this->hasMany(Broadcast::class);
    }
}
