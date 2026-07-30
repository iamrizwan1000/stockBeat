<?php

namespace App\Actions\Billing;

use App\Models\SubscriptionEvent;
use App\Models\Team;
use Illuminate\Database\Eloquent\Collection;

/**
 * The seller's own billing history, read from `subscription_events` — the
 * same appended-only RevenueCat event log the admin customer-detail page
 * reads (`GetCustomerDetailAction`), just scoped to the caller's team. Until
 * this action existed the table was admin-read-only, so a seller had no way
 * to see what they'd been charged.
 *
 * **Deliberately not "invoices."** Billing runs entirely through IAP, so
 * Apple/Google are the merchant of record and issue the seller's actual tax
 * document — this is an informational transaction list, and no
 * StockBeat-branded invoice PDF should ever be generated from it.
 *
 * `NON_RENEWING_PURCHASE` rows (SMS/AI credit packs) are included, not
 * filtered out: they're real money the seller spent and belong in a billing
 * history just as much as a subscription renewal does.
 *
 * Bounded to the most recent `LIMIT` events with no pagination and no
 * filters, matching `AssistantController::index()`'s bounded-list precedent.
 * A subscription accrues on the order of 12-24 events a year, so the cap is
 * effectively a safety bound rather than a real ceiling, and a filter UI over
 * a list that short would be over-engineering (order invoices, which run to
 * hundreds of rows, are where filtering actually earns its keep).
 */
class ListBillingHistoryAction
{
    private const LIMIT = 100;

    /**
     * @return Collection<int, SubscriptionEvent>
     */
    public function handle(Team $team): Collection
    {
        return SubscriptionEvent::query()
            ->where('team_id', $team->id)
            ->orderByDesc('occurred_at')
            ->limit(self::LIMIT)
            ->get();
    }
}
