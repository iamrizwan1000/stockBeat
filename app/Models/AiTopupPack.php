<?php

namespace App\Models;

use App\Actions\Billing\ProcessRevenueCatEventAction;
use Database\Factories\AiTopupPackFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Admin-editable catalog of AI question top-up packs (consumable IAPs) —
 * the AI-question counterpart to `SmsTopupPack`, closing the "still-not-
 * built IAP" gap `AiUsageLedger`'s docblock previously called out. `key`
 * mirrors the RevenueCat product id ({@see ProcessRevenueCatEventAction}
 * looks the credited amount up here by key) and is immutable after
 * creation. `price_usd` is informational/display-reference only — the
 * actual IAP price is store-controlled in App Store Connect / Play
 * Console.
 *
 * @property int $id
 * @property string $key
 * @property string $name
 * @property int $ai_questions
 * @property string $price_usd
 * @property bool $active
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['key', 'name', 'ai_questions', 'price_usd', 'active', 'sort_order'])]
class AiTopupPack extends Model
{
    /** @use HasFactory<AiTopupPackFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ai_questions' => 'integer',
            'price_usd' => 'decimal:2',
            'active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
