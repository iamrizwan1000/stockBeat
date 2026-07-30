<?php

use App\Actions\Billing\SendSubscriptionStartedNotificationAction;
use App\Mail\SubscriptionExpiredMail;
use App\Mail\SubscriptionPaymentIssueMail;
use App\Mail\SubscriptionStartedMail;
use App\Models\Notification;
use App\Models\Subscription;
use App\Models\User;
use Kreait\Firebase\Contract\Messaging;
use Database\Seeders\PlanSeeder;
use Database\Seeders\SmsTopupPackSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PlanSeeder::class);
    $this->seed(SmsTopupPackSeeder::class);
    config(['services.revenuecat.webhook_secret' => 'test-secret']);
    Mail::fake();
});

function onboardedNotifyUser(): User
{
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    test()->postJson('/api/v1/profile/setup', [
        'name' => 'Jamie Seller',
        'sells_on' => ['woo'],
    ])->assertOk();

    return $user->fresh();
}

/**
 * @param  array<string, mixed>  $overrides
 */
function postNotifyEvent(int $appUserId, array $overrides = []): TestResponse
{
    return test()->postJson('/hooks/revenuecat', [
        'api_version' => '1.0',
        'event' => array_merge([
            'id' => (string) fake()->unique()->uuid(),
            'type' => 'INITIAL_PURCHASE',
            'app_user_id' => (string) $appUserId,
            'product_id' => 'pro:monthly',
            'store' => 'PLAY_STORE',
            'expiration_at_ms' => now()->addMonth()->getTimestampMs(),
        ], $overrides),
    ], ['Authorization' => 'Bearer test-secret']);
}

function notificationsOfType(User $user, string $type): int
{
    return Notification::query()->where('user_id', $user->id)->where('type', $type)->count();
}

test('an INITIAL_PURCHASE notifies the owner that their subscription is active', function () {
    $user = onboardedNotifyUser();

    postNotifyEvent($user->id, ['type' => 'INITIAL_PURCHASE', 'product_id' => 'pro:monthly'])->assertOk();

    expect(notificationsOfType($user, Notification::TYPE_SUBSCRIPTION_STARTED))->toBe(1);
    Mail::assertQueued(SubscriptionStartedMail::class, 1);
    Mail::assertQueued(SubscriptionStartedMail::class, function (SubscriptionStartedMail $mail) {
        return $mail->planName === 'Pro' && $mail->reason === SendSubscriptionStartedNotificationAction::REASON_NEW;
    });
});

test('a PRODUCT_CHANGE notifies with the new tier name and reads as a change, not a fresh purchase', function () {
    $user = onboardedNotifyUser();
    postNotifyEvent($user->id, ['type' => 'INITIAL_PURCHASE', 'product_id' => 'pro:monthly'])->assertOk();

    postNotifyEvent($user->id, ['type' => 'PRODUCT_CHANGE', 'product_id' => 'premium:monthly'])->assertOk();

    expect(notificationsOfType($user, Notification::TYPE_SUBSCRIPTION_STARTED))->toBe(2);
    Mail::assertQueued(SubscriptionStartedMail::class, function (SubscriptionStartedMail $mail) {
        return $mail->planName === 'Premium' && $mail->reason === SendSubscriptionStartedNotificationAction::REASON_PLAN_CHANGE;
    });
});

test('a BILLING_ISSUE notifies the owner at high priority and names the billing store', function () {
    $user = onboardedNotifyUser();
    postNotifyEvent($user->id, ['type' => 'INITIAL_PURCHASE'])->assertOk();

    postNotifyEvent($user->id, ['type' => 'BILLING_ISSUE'])->assertOk();

    $notification = Notification::query()
        ->where('user_id', $user->id)
        ->where('type', Notification::TYPE_SUBSCRIPTION_PAYMENT_ISSUE)
        ->sole();

    expect($notification->priority)->toBe(Notification::PRIORITY_HIGH);
    // The purchase came from PLAY_STORE, so the fix instruction must point at
    // Google — the card lives there, not in our app.
    Mail::assertQueued(SubscriptionPaymentIssueMail::class, function (SubscriptionPaymentIssueMail $mail) {
        return $mail->provider === 'google';
    });
});

test('an EXPIRATION notifies the owner at high priority that their stores were paused', function () {
    $user = onboardedNotifyUser();
    postNotifyEvent($user->id, ['type' => 'INITIAL_PURCHASE'])->assertOk();

    postNotifyEvent($user->id, ['type' => 'EXPIRATION'])->assertOk();

    $notification = Notification::query()
        ->where('user_id', $user->id)
        ->where('type', Notification::TYPE_SUBSCRIPTION_EXPIRED)
        ->sole();

    expect($notification->priority)->toBe(Notification::PRIORITY_HIGH);
    Mail::assertQueued(SubscriptionExpiredMail::class, 1);
});

test('a routine RENEWAL on an already-active subscription notifies nothing — it recurs every cycle and would be spam', function () {
    $user = onboardedNotifyUser();
    postNotifyEvent($user->id, ['type' => 'INITIAL_PURCHASE'])->assertOk();
    // Only the purchase notice so far; clear the slate so the renewal is the
    // only thing that could possibly add to it.
    $startedBefore = notificationsOfType($user, Notification::TYPE_SUBSCRIPTION_STARTED);

    postNotifyEvent($user->id, ['type' => 'RENEWAL'])->assertOk();

    expect(notificationsOfType($user, Notification::TYPE_SUBSCRIPTION_STARTED))->toBe($startedBefore);
    Mail::assertQueued(SubscriptionStartedMail::class, 1);
});

test('a RENEWAL that recovers a failed payment DOES notify — we owe the resolution after warning them', function () {
    $user = onboardedNotifyUser();
    postNotifyEvent($user->id, ['type' => 'INITIAL_PURCHASE'])->assertOk();
    // The card gets declined: seller is warned and put into grace.
    postNotifyEvent($user->id, ['type' => 'BILLING_ISSUE'])->assertOk();
    $startedBefore = notificationsOfType($user, Notification::TYPE_SUBSCRIPTION_STARTED);

    // They fix the card, the store retries successfully — which arrives as a
    // plain RENEWAL, indistinguishable by event name from a routine one.
    postNotifyEvent($user->id, ['type' => 'RENEWAL'])->assertOk();

    expect(notificationsOfType($user, Notification::TYPE_SUBSCRIPTION_STARTED))->toBe($startedBefore + 1);
    Mail::assertQueued(SubscriptionStartedMail::class, function (SubscriptionStartedMail $mail) {
        return $mail->reason === SendSubscriptionStartedNotificationAction::REASON_PAYMENT_RECOVERED;
    });
});

test('resubscribing after a lapse notifies even when it arrives as a RENEWAL rather than a fresh purchase', function () {
    $user = onboardedNotifyUser();
    postNotifyEvent($user->id, ['type' => 'INITIAL_PURCHASE'])->assertOk();
    // Subscription lapses: stores paused, rules off, seller told so.
    postNotifyEvent($user->id, ['type' => 'EXPIRATION'])->assertOk();
    $startedBefore = notificationsOfType($user, Notification::TYPE_SUBSCRIPTION_STARTED);

    // A resubscribe can land as either INITIAL_PURCHASE or RENEWAL depending on
    // the store and the gap length — the RENEWAL shape is the one that used to
    // go silent, leaving stores to come back with no explanation.
    postNotifyEvent($user->id, ['type' => 'RENEWAL'])->assertOk();

    expect(notificationsOfType($user, Notification::TYPE_SUBSCRIPTION_STARTED))->toBe($startedBefore + 1);
    Mail::assertQueued(SubscriptionStartedMail::class, function (SubscriptionStartedMail $mail) {
        return $mail->reason === SendSubscriptionStartedNotificationAction::REASON_REACTIVATED;
    });
});

test('an UNCANCELLATION on a still-active subscription notifies nothing — no state was ever lost', function () {
    $user = onboardedNotifyUser();
    postNotifyEvent($user->id, ['type' => 'INITIAL_PURCHASE'])->assertOk();
    postNotifyEvent($user->id, ['type' => 'CANCELLATION'])->assertOk();
    $startedBefore = notificationsOfType($user, Notification::TYPE_SUBSCRIPTION_STARTED);

    postNotifyEvent($user->id, ['type' => 'UNCANCELLATION'])->assertOk();

    expect(notificationsOfType($user, Notification::TYPE_SUBSCRIPTION_STARTED))->toBe($startedBefore);
});

test('a CANCELLATION notifies nothing — the subscription is still active until it lapses', function () {
    $user = onboardedNotifyUser();
    postNotifyEvent($user->id, ['type' => 'INITIAL_PURCHASE'])->assertOk();

    postNotifyEvent($user->id, ['type' => 'CANCELLATION'])->assertOk();

    expect(notificationsOfType($user, Notification::TYPE_SUBSCRIPTION_PAYMENT_ISSUE))->toBe(0);
    expect(notificationsOfType($user, Notification::TYPE_SUBSCRIPTION_EXPIRED))->toBe(0);
    Mail::assertNotQueued(SubscriptionExpiredMail::class);
    Mail::assertNotQueued(SubscriptionPaymentIssueMail::class);
});

test('an UNCANCELLATION notifies nothing — it restores a state that was never lost', function () {
    $user = onboardedNotifyUser();
    postNotifyEvent($user->id, ['type' => 'INITIAL_PURCHASE'])->assertOk();
    $startedBefore = notificationsOfType($user, Notification::TYPE_SUBSCRIPTION_STARTED);

    postNotifyEvent($user->id, ['type' => 'UNCANCELLATION'])->assertOk();

    expect(notificationsOfType($user, Notification::TYPE_SUBSCRIPTION_STARTED))->toBe($startedBefore);
});

test('a broken Firebase credential cannot break the billing webhook or suppress the email', function () {
    $user = onboardedNotifyUser();
    postNotifyEvent($user->id, ['type' => 'INITIAL_PURCHASE'])->assertOk();
    Mail::fake();

    // Simulate Firebase being misconfigured/unreachable at the container level
    // — exactly what a bad service-account credential does in production. Before
    // `Messaging` was resolved lazily this cascaded up through
    // ProcessRevenueCatEventAction (method-injected into the webhook controller)
    // and 500'd the whole request *before* the dedup row was written, so Apple
    // charged the customer while entitlements never updated.
    app()->bind(Messaging::class, function () {
        throw new RuntimeException('Invalid service account credentials.');
    });

    postNotifyEvent($user->id, ['type' => 'BILLING_ISSUE'])->assertOk();

    // Billing state still moved, the in-app record still exists, and — the part
    // that actually prevents churn — the email still went out.
    expect($user->ownedTeam->subscription->fresh()->status)->toBe(Subscription::STATUS_GRACE);
    expect(notificationsOfType($user, Notification::TYPE_SUBSCRIPTION_PAYMENT_ISSUE))->toBe(1);
    Mail::assertQueued(SubscriptionPaymentIssueMail::class, 1);
});

test('a redelivered event does not double-notify', function () {
    $user = onboardedNotifyUser();
    $event = ['id' => 'fixed-event-id', 'type' => 'BILLING_ISSUE'];

    postNotifyEvent($user->id, $event)->assertOk();
    postNotifyEvent($user->id, $event)->assertOk()->assertJsonPath('status', 'duplicate');

    expect(notificationsOfType($user, Notification::TYPE_SUBSCRIPTION_PAYMENT_ISSUE))->toBe(1);
    Mail::assertQueued(SubscriptionPaymentIssueMail::class, 1);
});

test('subscription notices never count against the team email_monthly quota', function () {
    $user = onboardedNotifyUser();
    $team = $user->ownedTeam;

    postNotifyEvent($user->id, ['type' => 'INITIAL_PURCHASE'])->assertOk();
    postNotifyEvent($user->id, ['type' => 'BILLING_ISSUE'])->assertOk();
    postNotifyEvent($user->id, ['type' => 'EXPIRATION'])->assertOk();

    // Three real notices were sent, but they're transactional account notices
    // about the seller's own billing — spending their own alert allowance on
    // being told their card was declined would be backwards.
    expect(Notification::query()->where('user_id', $user->id)->count())->toBeGreaterThanOrEqual(3);
    expect(Notification::emailsSentThisMonth($team))->toBe(0);
});

test('a SMS top-up purchase notifies nothing — it is not a subscription change', function () {
    $user = onboardedNotifyUser();

    postNotifyEvent($user->id, ['type' => 'NON_RENEWING_PURCHASE', 'product_id' => 'sms_100'])->assertOk();

    expect(notificationsOfType($user, Notification::TYPE_SUBSCRIPTION_STARTED))->toBe(0);
    Mail::assertNotQueued(SubscriptionStartedMail::class);
});
