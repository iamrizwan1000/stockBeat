<?php

namespace App\Actions\Billing;

use App\Models\AiTopupPack;
use App\Models\AiUsageLedger;
use App\Models\Plan;
use App\Models\SmsLedger;
use App\Models\SmsTopupPack;
use App\Models\Subscription;
use App\Models\SubscriptionEvent;
use App\Models\Team;
use Illuminate\Support\Carbon;

/**
 * The RevenueCat webhook state machine (Plan §6.1). `app_user_id = user_id`
 * (§6.1's identity-linking design) resolves straight to our `User`, and its
 * `currentTeam()` owns the one `Subscription` row per team. Consumable
 * products (`sms_100`/`sms_500`, `ai_50`/etc.) are handled separately from
 * the renewing subscription products since they affect `sms_ledger`/
 * `ai_usage_ledger`, not `subscriptions`.
 *
 * The credited SMS/AI-question amount is looked up from
 * `sms_topup_packs`/`ai_topup_packs` by product id (Plan §5/§8.7.3 —
 * admin-editable so a pack's credit amount can be corrected without an app
 * release); a product id with no matching pack row in either table is
 * simply not a top-up. `SUBSCRIPTION_PLAN_PRODUCTS` stays an
 * explicit whitelist, not a DB table — unlike `plans`/`plan_limits`
 * (business-tunable numbers, deliberately DB-driven per §5), these IDs are
 * store-controlled: adding a new one means creating it in App Store
 * Connect/Play Console first, so a code change happens either way.
 * `pro_monthly`/`pro_yearly` both map to the same `Plan::PRO` (yearly is
 * just cheaper, not a different tier) — but with 4 plan tiers now, this map
 * genuinely needs to carry *which* tier each product grants, not just a
 * pro/not-pro boolean. An unrecognized product_id is a no-op, not a silent
 * Pro grant.
 *
 * Idempotency (a redelivered webhook must never double-credit SMS) is the
 * caller's responsibility via `RevenueCatEvent` — this action assumes it's
 * only ever invoked once per genuine event id.
 *
 * Every event this action actually handles (subscription-tier events and SMS
 * top-up purchases alike) is additionally appended to `subscription_events`
 * (Plan §8.7.2 "subscription timeline") purely for history/LTV — this never
 * feeds back into the crediting/entitlement logic above. A price is recorded
 * only when the RevenueCat payload actually carried one: `price_in_purchased
 * _currency`+`currency` (the purchase-currency amount) if present, else a
 * `price` (RevenueCat's USD reference amount) with an assumed `currency` of
 * `USD`. Event types with no price in the payload (e.g. CANCELLATION,
 * EXPIRATION) are still logged for the timeline, just with a null price —
 * never a fabricated amount.
 */
class ProcessRevenueCatEventAction
{
    /**
     * Public so SyncRevenueCatSubscriberAction (POST /billing/sync's pull-based
     * reconciliation) can map product ids to plan tiers the same way this
     * webhook-driven, push-based path does — one whitelist, not two.
     */
    public const SUBSCRIPTION_PLAN_PRODUCTS = [
        'starter_monthly' => Plan::STARTER,
        'pro_monthly' => Plan::PRO,
        'pro_yearly' => Plan::PRO,
        'premium_monthly' => Plan::PREMIUM,
        'premium_yearly' => Plan::PREMIUM,
    ];

    public function __construct(
        private readonly ApplyDowngradeFreezeAction $applyFreeze,
        private readonly ReverseDowngradeFreezeAction $reverseFreeze,
    ) {}

    /**
     * @param  array<string, mixed>  $event
     */
    public function handle(Team $team, array $event): void
    {
        $productId = (string) ($event['product_id'] ?? '');
        $type = (string) ($event['type'] ?? '');

        $smsTopupPack = SmsTopupPack::query()->where('key', $productId)->first();

        if ($smsTopupPack !== null) {
            if ($type === 'NON_RENEWING_PURCHASE') {
                $this->creditSmsTopup($team, $smsTopupPack, $event);
                $this->logSubscriptionEvent($team, $type, $event);
            }

            return;
        }

        $aiTopupPack = AiTopupPack::query()->where('key', $productId)->first();

        if ($aiTopupPack !== null) {
            if ($type === 'NON_RENEWING_PURCHASE') {
                $this->creditAiTopup($team, $aiTopupPack, $event);
                $this->logSubscriptionEvent($team, $type, $event);
            }

            return;
        }

        if (isset(self::SUBSCRIPTION_PLAN_PRODUCTS[$productId])) {
            $this->applySubscriptionEvent($team, $type, $productId, self::SUBSCRIPTION_PLAN_PRODUCTS[$productId], $event);
            $this->logSubscriptionEvent($team, $type, $event);
        }
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function logSubscriptionEvent(Team $team, string $type, array $event): void
    {
        [$price, $currency] = $this->extractPrice($event);

        SubscriptionEvent::query()->create([
            'team_id' => $team->id,
            'event_type' => $type,
            'price' => $price,
            'currency' => $currency,
            'raw_payload' => $event,
            'occurred_at' => $this->msToDate($event['event_timestamp_ms'] ?? null) ?? now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array{0: float|null, 1: string|null}
     */
    private function extractPrice(array $event): array
    {
        if (is_numeric($event['price_in_purchased_currency'] ?? null) && is_string($event['currency'] ?? null) && $event['currency'] !== '') {
            return [(float) $event['price_in_purchased_currency'], (string) $event['currency']];
        }

        if (is_numeric($event['price'] ?? null)) {
            return [(float) $event['price'], 'USD'];
        }

        return [null, null];
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function creditSmsTopup(Team $team, SmsTopupPack $pack, array $event): void
    {
        $delta = $pack->sms_credits;
        $balanceAfter = SmsLedger::currentBalance($team->id) + $delta;

        SmsLedger::query()->create([
            'team_id' => $team->id,
            'delta' => $delta,
            'reason' => SmsLedger::REASON_TOPUP_IAP,
            'balance_after' => $balanceAfter,
            'meta' => ['product_id' => $pack->key, 'revenuecat_event' => $event],
        ]);
    }

    /**
     * `balance_after` follows the same convention `AskAssistantAction`'s
     * quota debit and `GrantBonusAiCreditsAction`'s admin comp use: the
     * running total of `topup_iap`-reason credit granted *this calendar
     * month* (see `AiUsageLedger::bonusGrantedThisMonth`), not a lifetime
     * wallet balance — a real IAP purchase and an admin comp are
     * indistinguishable from the ledger's point of view, only `meta`
     * differs.
     *
     * @param  array<string, mixed>  $event
     */
    private function creditAiTopup(Team $team, AiTopupPack $pack, array $event): void
    {
        $delta = $pack->ai_questions;
        $bonusBefore = AiUsageLedger::bonusGrantedThisMonth($team->id);

        AiUsageLedger::query()->create([
            'team_id' => $team->id,
            'delta' => $delta,
            'reason' => AiUsageLedger::REASON_TOPUP_IAP,
            'balance_after' => $bonusBefore + $delta,
            'meta' => ['product_id' => $pack->key, 'revenuecat_event' => $event],
        ]);
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function applySubscriptionEvent(Team $team, string $type, string $productId, string $planKey, array $event): void
    {
        $subscription = $team->subscription;

        if ($subscription === null) {
            $subscription = new Subscription(['team_id' => $team->id]);
        }

        match ($type) {
            // PRODUCT_CHANGE is exactly a tier upgrade/downgrade (e.g.
            // Pro -> Premium) — plan_key must move to the new product's
            // tier here, not just refresh status/expiry.
            'INITIAL_PURCHASE', 'RENEWAL', 'PRODUCT_CHANGE', 'UNCANCELLATION' => $subscription->fill([
                'status' => Subscription::STATUS_ACTIVE,
                'provider' => $this->mapStore($event['store'] ?? null),
                'rc_app_user_id' => $event['app_user_id'] ?? null,
                'product_id' => $productId !== '' ? $productId : $subscription->product_id,
                'plan_key' => $planKey,
                'expires_at' => $this->msToDate($event['expiration_at_ms'] ?? null),
                'renewed_at' => now(),
            ]),
            // A CANCELLATION is just auto-renew being turned off — the
            // subscription stays active until it actually lapses on
            // EXPIRATION (§6.1: "grace period... downgrade to Free on
            // EXPIRATION"), so this deliberately does not change status.
            'CANCELLATION' => null,
            'BILLING_ISSUE' => $subscription->fill(['status' => Subscription::STATUS_GRACE]),
            'EXPIRATION' => $subscription->fill([
                'status' => Subscription::STATUS_EXPIRED,
                'expires_at' => $this->msToDate($event['expiration_at_ms'] ?? null) ?? now(),
            ]),
            default => null,
        };

        $subscription->raw = $event;
        $subscription->save();

        // §6.4: "same logic applies to a lapsed Pro subscription" — an
        // expiring paid subscription freezes exactly like an expiring
        // trial; a reactivation (fresh purchase, renewal, tier change, or
        // turning auto-renew back on before it lapsed) springs it back.
        match ($type) {
            'EXPIRATION' => $this->applyFreeze->handle($team),
            'INITIAL_PURCHASE', 'RENEWAL', 'PRODUCT_CHANGE', 'UNCANCELLATION' => $this->reverseFreeze->handle($team),
            default => null,
        };
    }

    private function mapStore(?string $store): ?string
    {
        return match ($store) {
            'APP_STORE' => 'apple',
            'PLAY_STORE' => 'google',
            default => null,
        };
    }

    private function msToDate(mixed $milliseconds): ?Carbon
    {
        return is_numeric($milliseconds) ? Carbon::createFromTimestampMs((int) $milliseconds) : null;
    }
}
