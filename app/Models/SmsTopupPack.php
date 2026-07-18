<?php

namespace App\Models;

use App\Actions\Billing\ProcessRevenueCatEventAction;
use Database\Factories\SmsTopupPackFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Plan §5/§5.1/§6/§8.7.3: admin-editable catalog of SMS credit top-up packs
 * (consumable IAPs, e.g. `sms_100`/`sms_500`) — lets an admin add/retire a
 * pack or correct its display price without a mobile app release. `key`
 * mirrors the RevenueCat product id ({@see ProcessRevenueCatEventAction}
 * looks the credited amount up here by key) and is immutable after
 * creation. `price_usd` is informational/display-reference only — the
 * actual IAP price is store-controlled in App Store Connect / Play
 * Console.
 *
 * @property int $id
 * @property string $key
 * @property string $name
 * @property int $sms_credits
 * @property string $price_usd
 * @property bool $active
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['key', 'name', 'sms_credits', 'price_usd', 'active', 'sort_order'])]
class SmsTopupPack extends Model
{
    /** @use HasFactory<SmsTopupPackFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sms_credits' => 'integer',
            'price_usd' => 'decimal:2',
            'active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
