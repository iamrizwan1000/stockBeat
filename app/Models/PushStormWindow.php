<?php

namespace App\Models;

use Database\Factories\PushStormWindowFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Per-user rolling window tracking order-push volume, backing the
 * notification-storm protection (Plan §17.4: a flash sale producing 200
 * orders in 10 minutes must "auto-collapse to bundled summaries ... never
 * 200 pings"). One row per user, reset whenever the window has expired —
 * see `SendOrderPushWithStormProtectionAction`.
 *
 * @property int $id
 * @property int $user_id
 * @property Carbon $window_started_at
 * @property int $order_count
 * @property float $revenue_total
 * @property Carbon|null $bundle_sent_at
 */
#[Fillable(['user_id', 'window_started_at', 'order_count', 'revenue_total', 'bundle_sent_at'])]
class PushStormWindow extends Model
{
    /** @use HasFactory<PushStormWindowFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'window_started_at' => 'datetime',
            'bundle_sent_at' => 'datetime',
            'revenue_total' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
