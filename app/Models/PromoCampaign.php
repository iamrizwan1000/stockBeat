<?php

namespace App\Models;

use Database\Factories\PromoCampaignFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A promotion or discount campaign (Plan §8.7.4/§9). `offer_code` and
 * `intro_offer` are metadata-only records of what was configured in the
 * Apple/Google store consoles — this app never talks to those consoles, so
 * redemption/conversion stats for those two types stay whatever the admin
 * enters manually in `stats`; there's no verified RevenueCat payload field
 * that ties an event back to a specific offer-code campaign, so automatic
 * tracking would be fabricated. `server_comp` is the one type this app can
 * actually execute: `ApplyServerCompToSegmentAction` grants comp Pro days or
 * bonus SMS credits directly, and `stats` there reflects real applications.
 *
 * `config` shape by type:
 * - offer_code: {code_prefix?, discount_pct?, duration_months?}
 * - intro_offer: {intro_price?, intro_duration?}
 * - server_comp: {comp_type: pro_days|sms_credits, amount: int, segment_id?: int|null}
 *
 * @property int $id
 * @property string $name
 * @property string $type
 * @property string|null $store_ref
 * @property array<string, mixed>|null $config
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property int|null $created_by
 * @property array<string, mixed>|null $stats
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'type', 'store_ref', 'config', 'starts_at', 'ends_at', 'created_by', 'stats'])]
class PromoCampaign extends Model
{
    /** @use HasFactory<PromoCampaignFactory> */
    use HasFactory;

    public const TYPE_OFFER_CODE = 'offer_code';

    public const TYPE_INTRO_OFFER = 'intro_offer';

    public const TYPE_SERVER_COMP = 'server_comp';

    public const STORE_APPLE = 'apple';

    public const STORE_GOOGLE = 'google';

    public const COMP_TYPE_PRO_DAYS = 'pro_days';

    public const COMP_TYPE_SMS_CREDITS = 'sms_credits';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'config' => 'array',
            'stats' => 'array',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<AdminUser, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'created_by');
    }

    public function isActive(): bool
    {
        $now = now();

        return ($this->starts_at === null || $this->starts_at->lte($now))
            && ($this->ends_at === null || $this->ends_at->gte($now));
    }
}
