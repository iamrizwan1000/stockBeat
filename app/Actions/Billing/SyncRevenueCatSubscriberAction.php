<?php

namespace App\Actions\Billing;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Team;
use App\Support\Concurrency\IdempotencyGuard;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Pull-based counterpart to ProcessRevenueCatEventAction's webhook state
 * machine (Plan §17.5): POST /billing/sync calls this to fetch a
 * subscriber's *current* state directly from RevenueCat's REST API rather
 * than waiting for a webhook — the only way to reliably support "restore
 * purchases" (Apple/Google's own requirement, §6.1), since a restore on a
 * new device doesn't necessarily fire a webhook at all, and the belt-and-
 * suspenders complement to the (usually fast but not instant) webhook path
 * for a normal purchase.
 *
 * Deliberately does not touch sms_ledger/ai_usage_ledger — Apple explicitly
 * disallows "restoring" consumable purchases, so this only ever reconciles
 * the renewing subscription entitlement, never SMS/AI top-ups (those stay
 * webhook-only, NON_RENEWING_PURCHASE, credited exactly once).
 *
 * Fails open (§17.5: "RevenueCat outage -> never lock out a paying user")
 * — a failed/malformed API response leaves the existing `Subscription` row
 * untouched rather than erroring, since our own DB is already the honored
 * last-known-good state; the caller re-resolves entitlements from it either
 * way.
 */
class SyncRevenueCatSubscriberAction
{
    public function __construct(
        private readonly ApplyDowngradeFreezeAction $applyFreeze,
        private readonly ReverseDowngradeFreezeAction $reverseFreeze,
        private readonly SendSubscriptionExpiredNotificationAction $notifyExpired,
    ) {}

    public function handle(Team $team, string $rcAppUserId): void
    {
        $secretKey = config('services.revenuecat.secret_api_key');

        if (! is_string($secretKey) || $secretKey === '') {
            return;
        }

        $response = Http::withToken($secretKey)
            ->get("https://api.revenuecat.com/v1/subscribers/{$rcAppUserId}");

        if ($response->failed()) {
            return;
        }

        /** @var array<string, mixed> $subscriptions */
        $subscriptions = (array) $response->json('subscriber.subscriptions', []);

        $best = $this->pickBestSubscription($subscriptions);

        $subscription = $team->subscription ?? new Subscription(['team_id' => $team->id]);
        $subscription->rc_app_user_id = $rcAppUserId;

        if ($best === null) {
            // No known product on file at all for this subscriber — leave
            // status alone rather than guessing; EXPIRATION always arrives
            // via the webhook for a subscription that genuinely lapses.
            $subscription->save();

            return;
        }

        [$productId, $status, $expiresAt, $provider] = $best;
        $wasEntitled = $subscription->exists && $subscription->isEntitled();

        $subscription->fill([
            'provider' => $provider,
            'product_id' => $productId,
            'plan_key' => ProcessRevenueCatEventAction::SUBSCRIPTION_PLAN_PRODUCTS[$productId] ?? $subscription->plan_key,
            'status' => $status,
            'expires_at' => $expiresAt,
        ]);
        $subscription->save();

        $nowEntitled = $subscription->isEntitled();

        if ($wasEntitled && ! $nowEntitled) {
            $this->applyFreeze->handle($team);

            // Backfills the one notification a lost webhook would otherwise
            // lose for good: without this, a seller whose `EXPIRATION` webhook
            // never arrived gets their stores paused and rules switched off by
            // the freeze above with no explanation at all — the state
            // self-heals here, but silently.
            //
            // Guarded because two devices syncing at once would both read
            // `$wasEntitled` as true before either saved. The webhook side is
            // separately transition-gated (see `ProcessRevenueCatEventAction`),
            // so a late-arriving webhook for a lapse discovered here won't
            // duplicate this either.
            IdempotencyGuard::once("subscription-expired-notice:{$team->id}", 300, fn () => $this->notifyExpired->handle($team));
        } elseif (! $wasEntitled && $nowEntitled) {
            $this->reverseFreeze->handle($team);

            // Deliberately no notification on the way *up* from here. The
            // client is told to call this endpoint immediately after a purchase
            // or a restore, so this branch fires while the seller is watching
            // the screen having just acted — and the webhook is about to send
            // the real confirmation. Notifying here would race it and produce
            // two emails, one of them wrongly worded as a "welcome back".
        }
    }

    /**
     * @param  array<string, mixed>  $subscriptions
     * @return array{0: string, 1: string, 2: ?Carbon, 3: ?string}|null
     */
    private function pickBestSubscription(array $subscriptions): ?array
    {
        $candidates = [];

        foreach ($subscriptions as $productId => $details) {
            if (! isset(ProcessRevenueCatEventAction::SUBSCRIPTION_PLAN_PRODUCTS[$productId]) || ! is_array($details)) {
                continue;
            }

            $expiresAt = is_string($details['expires_date'] ?? null) ? Carbon::parse($details['expires_date']) : null;
            $inGracePeriod = is_string($details['billing_issues_detected_at'] ?? null) && ($expiresAt === null || $expiresAt->isFuture());

            $status = match (true) {
                $inGracePeriod => Subscription::STATUS_GRACE,
                $expiresAt === null || $expiresAt->isFuture() => Subscription::STATUS_ACTIVE,
                default => Subscription::STATUS_EXPIRED,
            };

            $provider = match ($details['store'] ?? null) {
                'app_store' => 'apple',
                'play_store' => 'google',
                default => null,
            };

            $candidates[] = [$productId, $status, $expiresAt, $provider];
        }

        if ($candidates === []) {
            return null;
        }

        // Prefer any currently-entitled (active/grace) candidate over an
        // expired one; among entitled candidates, the highest plan tier
        // wins (a subscriber can't hold two renewing subscriptions in
        // practice, but this stays correct if they somehow do).
        usort($candidates, function (array $a, array $b) {
            $aEntitled = in_array($a[1], [Subscription::STATUS_ACTIVE, Subscription::STATUS_GRACE], true);
            $bEntitled = in_array($b[1], [Subscription::STATUS_ACTIVE, Subscription::STATUS_GRACE], true);

            if ($aEntitled !== $bEntitled) {
                return $bEntitled <=> $aEntitled;
            }

            $aTier = $this->tierRank(ProcessRevenueCatEventAction::SUBSCRIPTION_PLAN_PRODUCTS[$a[0]]);
            $bTier = $this->tierRank(ProcessRevenueCatEventAction::SUBSCRIPTION_PLAN_PRODUCTS[$b[0]]);

            return $bTier <=> $aTier;
        });

        return $candidates[0];
    }

    private function tierRank(string $planKey): int
    {
        return match ($planKey) {
            Plan::PREMIUM => 3,
            Plan::PRO => 2,
            Plan::STARTER => 1,
            default => 0,
        };
    }
}
