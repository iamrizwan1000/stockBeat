<?php

namespace App\Actions\Notifications;

use App\Models\Order;
use App\Models\PushStormWindow;
use App\Models\StoreConnection;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Storm protection for order-triggered pushes (Plan §17.4: "Notification
 * storm (flash sale: 200 orders in 10 min) → auto-collapse to bundled
 * summaries ('47 new orders in the last 10 min · $3,912'), never 200
 * pings"). Tracks a rolling per-user window of order-push volume; once
 * THRESHOLD is crossed inside the window, individual pushes stop firing
 * and a single bundled summary push fires instead, then stays silent for
 * the rest of the window. The in-app notification center still gets one
 * record per order regardless (`SendPushNotificationAction`'s `$deliver`
 * flag) — the center is a record of what fired, not proof of delivery,
 * same convention as mute/quiet-hours elsewhere.
 *
 * THRESHOLD/WINDOW_MINUTES are fixed, documented constants rather than
 * admin-configurable plan limits — same arbitrary-but-documented-threshold
 * convention as `GetOpsHealthSnapshotAction`'s rule-execution abuse guard.
 */
class SendOrderPushWithStormProtectionAction
{
    private const THRESHOLD = 5;

    private const WINDOW_MINUTES = 10;

    public function __construct(
        private readonly SendPushNotificationAction $sendPush,
    ) {}

    /**
     * @param  array<string, mixed>  $extraData  Merged into every `data` payload
     *                                           built here (e.g. `trigger` —
     *                                           `DispatchRuleActionsAction`
     *                                           stamping "where this alert came
     *                                           from" onto the Notification
     *                                           Center row, added 2026-07-24).
     */
    public function handle(User $user, Order $order, string $title, string $body, ?string $sound = null, ?StoreConnection $connection = null, array $extraData = []): string
    {
        $orderTotal = (float) ($order->total_base_currency ?? $order->total);
        $data = [...$extraData, 'order_id' => (string) $order->id];

        $window = PushStormWindow::query()->firstOrNew(['user_id' => $user->id]);

        // Column is NOT NULL by schema, so an existing row's window_started_at
        // is only ever missing on a not-yet-persisted (firstOrNew) instance —
        // `! $window->exists` already covers that case.
        $expired = ! $window->exists
            || $window->window_started_at->lt(Carbon::now()->subMinutes(self::WINDOW_MINUTES));

        if ($expired) {
            $window->fill([
                'window_started_at' => Carbon::now(),
                'order_count' => 1,
                'revenue_total' => $orderTotal,
                'bundle_sent_at' => null,
            ])->save();

            return $this->sendPush->handle($user, $title, $body, $data, sound: $sound, connection: $connection);
        }

        $window->order_count++;
        $window->revenue_total = (float) $window->revenue_total + $orderTotal;

        if ($window->order_count <= self::THRESHOLD) {
            $window->save();

            return $this->sendPush->handle($user, $title, $body, $data, sound: $sound, connection: $connection);
        }

        // Log this order to the in-app center, but never fan out its own
        // FCM push once the window is in storm mode.
        $this->sendPush->handle($user, $title, $body, $data, deliver: false, connection: $connection);

        if ($window->bundle_sent_at !== null) {
            $window->save();

            return 'bundled_suppressed';
        }

        $window->bundle_sent_at = Carbon::now();
        $window->save();

        $summary = "{$window->order_count} new orders in the last ".self::WINDOW_MINUTES.' min · $'.number_format((float) $window->revenue_total, 2);

        return $this->sendPush->handle($user, 'Order storm', $summary, ['storm' => 'true'], sound: $sound, connection: $connection);
    }
}
