<?php

namespace App\Models;

use Database\Factories\FxRateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Daily FX rate (Plan §9/§4.6): `1 base = rate quote`. Sourced from a real,
 * free, no-API-key exchange rate feed (Frankfurter, ECB-derived) — see
 * `SyncFxRatesAction`. Same-currency conversions are never stored (a rate
 * of 1.0 for e.g. AUD→AUD would just be noise); callers should short-circuit
 * on `base === quote` before ever consulting this table.
 *
 * @property int $id
 * @property string $base
 * @property string $quote
 * @property float $rate
 * @property Carbon $date
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['base', 'quote', 'rate', 'date'])]
class FxRate extends Model
{
    /** @use HasFactory<FxRateFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rate' => 'float',
            'date' => 'date',
        ];
    }
}
