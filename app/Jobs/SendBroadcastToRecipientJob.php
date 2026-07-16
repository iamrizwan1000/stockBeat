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
 * count — never a fabricated "delivered"/"opened" figure, since no
 * receipt/open-pixel infra exists.
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

        $status = match ($this->channel) {
            Broadcast::CHANNEL_PUSH => $this->sendPush($broadcast, $user, $renderTemplate),
            Broadcast::CHANNEL_EMAIL => $this->sendEmail($broadcast, $user, $renderTemplate),
            Broadcast::CHANNEL_BANNER => $this->sendBanner($broadcast, $user, $renderTemplate),
            default => BroadcastDelivery::STATUS_FAILED,
        };

        BroadcastDelivery::query()->create([
            'broadcast_id' => $broadcast->id,
            'user_id' => $user->id,
            'channel' => $this->channel,
            'status' => $status,
        ]);
    }

    private function sendPush(Broadcast $broadcast, User $user, RenderBroadcastTemplateAction $renderTemplate): string
    {
        /** @var SendPushNotificationAction $action */
        $action = app(SendPushNotificationAction::class);

        $result = $action->handle(
            $user,
            $renderTemplate->handle($broadcast->title, $user),
            $renderTemplate->handle($broadcast->body, $user),
            ['broadcast_id' => $broadcast->id],
            Notification::TYPE_ADMIN_BROADCAST,
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
     * Broadcast emails are marketing communications by definition — always
     * gated on `marketing_opt_in` (Plan §8.7.5 "marketing emails honor
     * unsubscribe"), unlike rule-notification emails which are transactional.
     */
    private function sendEmail(Broadcast $broadcast, User $user, RenderBroadcastTemplateAction $renderTemplate): string
    {
        if (! $user->marketing_opt_in) {
            return BroadcastDelivery::STATUS_SKIPPED_NO_CONSENT;
        }

        Mail::to($user->email)->queue(new BroadcastMail(
            $renderTemplate->handle($broadcast->title, $user),
            $renderTemplate->handle($broadcast->body, $user),
        ));

        return BroadcastDelivery::STATUS_SENT;
    }

    /**
     * In-app banner: always logged to the notification center regardless of
     * personal push/email preferences — same rule `SendPushNotificationAction`
     * follows for its own record ("the center is a record of what fired").
     */
    private function sendBanner(Broadcast $broadcast, User $user, RenderBroadcastTemplateAction $renderTemplate): string
    {
        Notification::query()->create([
            'user_id' => $user->id,
            'type' => Notification::TYPE_ADMIN_BROADCAST,
            'title' => $renderTemplate->handle($broadcast->title, $user),
            'body' => $renderTemplate->handle($broadcast->body, $user),
            'data' => ['broadcast_id' => $broadcast->id],
        ]);

        return BroadcastDelivery::STATUS_SENT;
    }
}
