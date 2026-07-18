<?php

namespace App\Actions\Admin;

use App\Actions\Billing\ConvertToBaseCurrencyAction;
use App\Models\SubscriptionEvent;
use App\Models\Team;

/**
 * Plan §8.7.2 "payments/LTV" — total revenue actually collected for a team,
 * summed from `subscription_events` (every priced RevenueCat transaction:
 * subscription purchases/renewals/tier changes and SMS top-ups alike) and
 * converted into the team owner's `base_currency` via the same
 * `ConvertToBaseCurrencyAction`/`FxRate` convention Analytics already uses
 * (Plan §4.6/§9) — no separate currency-handling scheme invented here.
 *
 * Not every event carries a price (see `ProcessRevenueCatEventAction`'s own
 * comment on this), and not every priced event is convertible (no `FxRate`
 * row yet for that currency pair on that date). Both gaps are counted and
 * returned alongside the total rather than silently dropped, so the admin
 * page can be honest about "this LTV figure may be incomplete" instead of
 * implying precision that isn't there.
 */
class ComputeCustomerLtvAction
{
    public function __construct(
        private readonly ConvertToBaseCurrencyAction $convertToBaseCurrency,
    ) {}

    /**
     * @return array{total: float, currency: string, events_included: int, events_excluded_no_price: int, events_excluded_no_fx_rate: int}
     */
    public function handle(Team $team): array
    {
        $baseCurrency = $team->owner->base_currency;

        $events = SubscriptionEvent::query()
            ->where('team_id', $team->id)
            ->get(['price', 'currency', 'occurred_at']);

        $total = 0.0;
        $included = 0;
        $excludedNoPrice = 0;
        $excludedNoFxRate = 0;

        foreach ($events as $event) {
            if ($event->price === null || $event->currency === null) {
                $excludedNoPrice++;

                continue;
            }

            $converted = $this->convertToBaseCurrency->handle(
                $event->price,
                $event->currency,
                $baseCurrency,
                $event->occurred_at,
            );

            if ($converted === null) {
                $excludedNoFxRate++;

                continue;
            }

            $total += $converted;
            $included++;
        }

        return [
            'total' => round($total, 2),
            'currency' => $baseCurrency,
            'events_included' => $included,
            'events_excluded_no_price' => $excludedNoPrice,
            'events_excluded_no_fx_rate' => $excludedNoFxRate,
        ];
    }
}
