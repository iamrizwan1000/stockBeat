<?php

namespace App\Models;

use Database\Factories\PlanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $key
 * @property string $name
 * @property bool $active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['key', 'name', 'active'])]
class Plan extends Model
{
    /** @use HasFactory<PlanFactory> */
    use HasFactory;

    public const FREE = 'free';

    public const STARTER = 'starter';

    public const PRO = 'pro';

    public const PREMIUM = 'premium';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<PlanLimit, $this>
     */
    public function limits(): HasMany
    {
        return $this->hasMany(PlanLimit::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function limitsArray(): array
    {
        return $this->limits->pluck('value', 'key')->all();
    }
}
