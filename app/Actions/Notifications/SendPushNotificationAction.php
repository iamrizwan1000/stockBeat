<?php

namespace App\Actions\Notifications;

use App\Models\Device;
use App\Models\Notification;
use App\Models\User;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Exception\Messaging\NotFound;
use Kreait\Firebase\Exception\MessagingException;
use Kreait\Firebase\Messaging\AndroidConfig;
use Kreait\Firebase\Messaging\ApnsConfig;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification as FirebaseNotification;

/**
 * Sends a real push notification via FCM (Plan §8.2) to every device the
 * user has registered, and always logs it to the in-app notification
 * center regardless of delivery outcome — the center is a record of what
 * fired, not proof a phone received it. Prunes a device on `NotFound`
 * (unregistered token, Plan §17.4). Personal notification preferences
 * (Plan §4.8) gate the actual FCM send only — muting push or being in
 * quiet hours never hides the notification from the in-app center itself.
 * `$deliver = false` gates the FCM send the same way — used by
 * `SendOrderPushWithStormProtectionAction` (Plan §17.4 notification-storm
 * bundling) to still log an in-app record per order without fanning out an
 * individual ping once a burst has crossed into "bundle mode".
 *
 * `$sound` is a rule's configured custom sound key (Plan §4.4: "custom
 * sound option — the 'cha-ching'", see `Rule::sounds()`) — when given, it's
 * set on both the iOS (`apns.payload.aps.sound`) and Android
 * (`android.notification.sound`) portions of the FCM payload so the same
 * key resolves to a bundled sound file on whichever platform the device
 * is. `null` (the default) leaves the payload untouched — the device/app's
 * own default notification sound plays, same as before this existed.
 *
 * `$onNotificationCreated`, when given, is invoked with the freshly created
 * `Notification` row right after it's persisted — used by
 * `SendBroadcastToRecipientJob` to link a `BroadcastDelivery` to it
 * (`notification_id`) so a later "mark read" on that notification can stamp
 * the delivery's `opened_at` (Plan §8.7.5 open/read tracking). Optional and
 * appended last so every existing caller is unaffected.
 */
class SendPushNotificationAction
{
    public function __construct(
        private readonly Messaging $messaging,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(User $user, string $title, string $body, array $data = [], string $type = Notification::TYPE_RULE_PUSH, bool $deliver = true, ?string $sound = null, ?callable $onNotificationCreated = null): string
    {
        $notification = Notification::query()->create([
            'user_id' => $user->id,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'data' => $data,
        ]);

        if ($onNotificationCreated !== null) {
            $onNotificationCreated($notification);
        }

        if (! $deliver) {
            return 'bundled_suppressed';
        }

        $preference = $user->notificationPreference;

        if ($preference !== null && ! $preference->push_enabled) {
            return 'muted_by_preference';
        }

        if ($preference !== null && $preference->isWithinQuietHours()) {
            return 'quiet_hours';
        }

        $devices = Device::query()
            ->where('user_id', $user->id)
            ->whereNotNull('push_token')
            ->get();

        if ($devices->isEmpty()) {
            return 'no_devices';
        }

        $sentAny = false;

        /** @var array<non-empty-string, string> $stringData */
        $stringData = [];
        foreach ($data as $key => $value) {
            if ($key === '') {
                continue;
            }

            $stringData[$key] = (string) $value;
        }

        foreach ($devices as $device) {
            if ($device->push_token === null || $device->push_token === '') {
                continue;
            }

            $message = CloudMessage::new()
                ->withToken($device->push_token)
                ->withNotification(FirebaseNotification::create($title, $body))
                ->withData($stringData);

            if ($sound !== null && $sound !== '') {
                $message = $message
                    ->withApnsConfig(ApnsConfig::new()->withSound($sound))
                    ->withAndroidConfig(AndroidConfig::new()->withSound($sound));
            }

            try {
                $this->messaging->send($message);
                $sentAny = true;
            } catch (NotFound) {
                $device->delete();
            } catch (MessagingException) {
                // Left intact — this attempt failed for a reason other than
                // an unregistered token, so the device may still be valid.
            }
        }

        return $sentAny ? 'sent' : 'failed';
    }
}
