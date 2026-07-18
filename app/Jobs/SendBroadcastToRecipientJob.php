<?php

namespace App\Jobs;

use App\Actions\Admin\Messaging\RenderBroadcastTemplateAction;
use App\Actions\Notifications\SendPushNotificationAction;
use App\Mail\BroadcastMail;
use App\Models\Broadcast;
use App\Models\BroadcastDelivery;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

/**
 * Delivers one broadcast to one recipient on one channel (Plan §8.7.5),
 * recording a real `BroadcastDelivery` row so the admin sees an accurate
 * count. The delivery row is created up front (not after sending) so its
 * id exists before the channel handlers run — the email tracking
 * pixel/unsubscribe link and the push→notification open-tracking link both
 * need that id.
 */
class SendBroadcastToRecipientJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int $broadcastId,
        public readonly int $userId,
        public readonly string $channel,
    ) {}

    public function handle(RenderBroadcastTemplateAction $renderTemplate): void
    {
        $broadcast = Broadcast::query()->find($this->broadcastId);
        $user = User::query()->find($this->userId);

        if ($broadcast === null || $user === null) {
            return;
        }

        $delivery = BroadcastDelivery::query()->create([
            'broadcast_id' => $broadcast->id,
            'user_id' => $user->id,
            'channel' => $this->channel,
            'status' => BroadcastDelivery::STATUS_FAILED,
        ]);

        $status = match ($this->channel) {
            Broadcast::CHANNEL_PUSH => $this->sendPush($broadcast, $user, $renderTemplate, $delivery),
            Broadcast::CHANNEL_EMAIL => $this->sendEmail($broadcast, $user, $renderTemplate, $delivery),
            Broadcast::CHANNEL_BANNER => $this->sendBanner($broadcast, $user, $renderTemplate, $delivery),
            default => BroadcastDelivery::STATUS_FAILED,
        };

        // `update()` also persists `notification_id` if a channel handler
        // set it on the in-memory model without saving (see `sendPush`/
        // `sendBanner`) — one write instead of two.
        $delivery->update(['status' => $status]);
    }

    private function sendPush(Broadcast $broadcast, User $user, RenderBroadcastTemplateAction $renderTemplate, BroadcastDelivery $delivery): string
    {
        /** @var SendPushNotificationAction $action */
        $action = app(SendPushNotificationAction::class);

        $result = $action->handle(
            $user,
            $renderTemplate->handle($broadcast->title, $user),
            $renderTemplate->handle($broadcast->body, $user),
            ['broadcast_id' => $broadcast->id],
            Notification::TYPE_ADMIN_BROADCAST,
            true,
            null,
            function (Notification $notification) use ($delivery): void {
                $delivery->notification_id = $notification->id;
            },
        );

        return match ($result) {
            'sent' => BroadcastDelivery::STATUS_SENT,
            'muted_by_preference' => BroadcastDelivery::STATUS_SKIPPED_MUTED,
            'quiet_hours' => BroadcastDelivery::STATUS_SKIPPED_QUIET_HOURS,
            'no_devices' => BroadcastDelivery::STATUS_SKIPPED_NO_DEVICES,
            default => BroadcastDelivery::STATUS_FAILED,
        };
    }

    /**
     * Broadcast emails are marketing communications by definition. Two
     * independent gates apply, most fundamental first: `marketing_opt_in`
     * (Plan §8.7.5 "marketing emails honor unsubscribe" — never having
     * consented at all) and then the personal `email_enabled` preference
     * (Plan §4.8, previously only enforced for push) — a genuine one-click
     * unsubscribe link in the email flips this off via
     * `UpdateNotificationPreferencesAction`, so a future send correctly
     * reports `skipped_unsubscribed` for that recipient rather than
     * silently vanishing from the count.
     */
    private function sendEmail(Broadcast $broadcast, User $user, RenderBroadcastTemplateAction $renderTemplate, BroadcastDelivery $delivery): string
    {
        if (! $user->marketing_opt_in) {
            return BroadcastDelivery::STATUS_SKIPPED_NO_CONSENT;
        }

        $preference = $user->notificationPreference;

        if ($preference !== null && ! $preference->email_enabled) {
            return BroadcastDelivery::STATUS_SKIPPED_UNSUBSCRIBED;
        }

        Mail::to($user->email)->queue(new BroadcastMail(
            $renderTemplate->handle($broadcast->title, $user),
            $renderTemplate->handle($broadcast->body, $user),
            $delivery->id,
        ));

        return BroadcastDelivery::STATUS_SENT;
    }

    /**
     * In-app banner: always logged to the notification center regardless of
     * personal push/email preferences — same rule `SendPushNotificationAction`
     * follows for its own record ("the center is a record of what fired").
     * Linked to the delivery the same way push is, so marking it read in
     * the notification center stamps `opened_at` here too.
     */
    private function sendBanner(Broadcast $broadcast, User $user, RenderBroadcastTemplateAction $renderTemplate, BroadcastDelivery $delivery): string
    {
        $notification = Notification::query()->create([
            'user_id' => $user->id,
            'type' => Notification::TYPE_ADMIN_BROADCAST,
            'title' => $renderTemplate->handle($broadcast->title, $user),
            'body' => $renderTemplate->handle($broadcast->body, $user),
            'data' => ['broadcast_id' => $broadcast->id],
        ]);

        $delivery->notification_id = $notification->id;

        return BroadcastDelivery::STATUS_SENT;
    }
}
