<?php

namespace App\Actions\Billing;

use App\Models\SmsLedger;
use App\Models\Subscription;
use App\Models\Team;
use Illuminate\Support\Carbon;

/**
 * The RevenueCat webhook state machine (Plan §6.1). `app_user_id = user_id`
 * (§6.1's identity-linking design) resolves straight to our `User`, and its
 * `currentTeam()` owns the one `Subscription` row per team. Consumable
 * products (`sms_100`/`sms_500`) are handled separately from the renewing
 * subscription products (`pro_monthly`/`pro_yearly`) since they affect
 * `sms_ledger`, not `subscriptions`.
 *
 * Both `SMS_TOPUP_PRODUCTS` and `SUBSCRIPTION_PRODUCTS` are an explicit
 * whitelist, not a DB table — `pro_monthly`/`pro_yearly` both map to the
 * single `Plan::PRO` tier (§5: yearly is just cheaper, not a different
 * plan), so there's no N:1 mapping to store. Unlike `plans`/`plan_limits`
 * (business-tunable numbers, deliberately DB-driven per §5), these IDs are
 * store-controlled — adding a new one means creating it in App Store
 * Connect/Play Console first, so a code change happens either way. An
 * unrecognized product_id is a no-op, not a silent Pro grant.
 *
 * Idempotency (a redelivered webhook must never double-credit SMS) is the
 * caller's responsibility via `RevenueCatEvent` — this action assumes it's
 * only ever invoked once per genuine event id.
 */
class ProcessRevenueCatEventAction
{
    private const SMS_TOPUP_PRODUCTS = [
        'sms_100' => 100,
        'sms_500' => 500,
    ];

    private const SUBSCRIPTION_PRODUCTS = ['pro_monthly', 'pro_yearly'];

    /**
     * @param  array<string, mixed>  $event
     */
    public function handle(Team $team, array $event): void
    {
        $productId = (string) ($event['product_id'] ?? '');
        $type = (string) ($event['type'] ?? '');

        if (isset(self::SMS_TOPUP_PRODUCTS[$productId])) {
            if ($type === 'NON_RENEWING_PURCHASE') {
                $this->creditSmsTopup($team, $productId, $event);
            }

            return;
        }

        if (in_array($productId, self::SUBSCRIPTION_PRODUCTS, true)) {
            $this->applySubscriptionEvent($team, $type, $productId, $event);
        }
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function creditSmsTopup(Team $team, string $productId, array $event): void
    {
        $delta = self::SMS_TOPUP_PRODUCTS[$productId];
        $balanceAfter = SmsLedger::currentBalance($team->id) + $delta;

        SmsLedger::query()->create([
            'team_id' => $team->id,
            'delta' => $delta,
            'reason' => SmsLedger::REASON_TOPUP_IAP,
            'balance_after' => $balanceAfter,
            'meta' => ['product_id' => $productId, 'revenuecat_event' => $event],
        ]);
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function applySubscriptionEvent(Team $team, string $type, string $productId, array $event): void
    {
        $subscription = $team->subscription;

        if ($subscription === null) {
            $subscription = new Subscription(['team_id' => $team->id]);
        }

        match ($type) {
            'INITIAL_PURCHASE', 'RENEWAL', 'PRODUCT_CHANGE', 'UNCANCELLATION' => $subscription->fill([
                'status' => Subscription::STATUS_ACTIVE,
                'provider' => $this->mapStore($event['store'] ?? null),
                'rc_app_user_id' => $event['app_user_id'] ?? null,
                'product_id' => $productId !== '' ? $productId : $subscription->product_id,
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
