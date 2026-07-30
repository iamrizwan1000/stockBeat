<?php

namespace App\Actions\Billing;

use App\Actions\Notifications\SendPushNotificationAction;
use App\Mail\SubscriptionStartedMail;
use App\Models\Notification;
use App\Models\Plan;
use App\Models\Team;
use Illuminate\Support\Facades\Mail;

/**
 * Confirms a subscription actually started, or that a tier change landed
 * (Plan §6.1). Sent to the team owner, same convention as
 * `SendTrialReminderNotificationAction` — the owner is the only person who
 * sees billing state today.
 *
 * Covers four distinct moments, all of which end with "your plan is active"
 * — a first purchase, a tier change, a payment recovering out of grace, and
 * a lapsed seller resubscribing. They share one notification type because
 * they're the same fact from the client's point of view; only the copy
 * differs. **A routine `RENEWAL` is not one of them** — see
 * `ProcessRevenueCatEventAction`'s dispatch block for why a renewal that was
 * already active notifies nothing while one recovering from grace/expiry
 * does.
 *
 * Unlike the payment-issue and expiry notices, this one is deliberately
 * *preference-gated* (no `bypassPreferences`): it's a confirmation of
 * something the seller just deliberately did, not an account problem needing
 * their attention, so a muted-push preference should still be honored. The
 * in-app notification center gets its row either way
 * (`SendPushNotificationAction` always logs).
 */
class SendSubscriptionStartedNotificationAction
{
    public const REASON_NEW = 'new';

    public const REASON_PLAN_CHANGE = 'plan_change';

    /** Payment succeeded on retry after a `BILLING_ISSUE` grace period. */
    public const REASON_PAYMENT_RECOVERED = 'payment_recovered';

    /** A previously-expired subscription was purchased again. */
    public const REASON_REACTIVATED = 'reactivated';

    public function __construct(
        private readonly SendPushNotificationAction $sendPush,
    ) {}

    public function handle(Team $team, string $planKey, string $reason = self::REASON_NEW): void
    {
        $owner = $team->owner;

        if ($owner === null) {
            return;
        }

        $planName = $this->planName($planKey);

        [$title, $body] = match ($reason) {
            self::REASON_PLAN_CHANGE => [
                "You're now on {$planName}",
                "Your plan change went through — {$planName} limits are active now.",
            ],
            // Closes the loop on the payment-issue notice: we told them the
            // charge failed, so we owe them the confirmation that it worked.
            self::REASON_PAYMENT_RECOVERED => [
                'Payment went through',
                "You're all set — nothing was interrupted and {$planName} stays active.",
            ],
            // They were told stores were paused and rules switched off when it
            // lapsed, so say plainly that those are back.
            self::REASON_REACTIVATED => [
                "Welcome back to {$planName}",
                'Your stores are active again and your rules are back on, exactly as they were.',
            ],
            default => [
                "{$planName} is active",
                "Thanks for subscribing. Your {$planName} limits are active now.",
            ],
        };

        $this->sendPush->handle(
            $owner,
            $title,
            $body,
            ['plan' => $planKey],
            Notification::TYPE_SUBSCRIPTION_STARTED,
        );

        Mail::to($owner->email)->queue(new SubscriptionStartedMail($planName, $reason));
    }

    /**
     * `plans.name` is the display label ("Pro"), but this must never hard-fail
     * over an unseeded `plans` table — it's called from the webhook path,
     * which has to keep working, so an unresolvable key falls back to the key
     * itself rather than throwing (same defensive posture
     * `GrantMonthlySmsCreditsAction` takes for its own plan lookup).
     */
    private function planName(string $planKey): string
    {
        $name = Plan::query()->where('key', $planKey)->value('name');

        return is_string($name) && $name !== '' ? $name : ucfirst($planKey);
    }
}
