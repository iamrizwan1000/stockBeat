<?php

namespace App\Events;

use App\Models\SupportMessage;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Real-time delivery for support chat (Plan §4.9): "WebSocket (Reverb) when
 * the user is in-app." Broadcast is queued implicitly via `ShouldBroadcast`
 * (not `ShouldBroadcastNow`) so a Reverb outage degrades to "arrives on next
 * poll" rather than blocking the request that created the message.
 */
class SupportMessageSent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly SupportMessage $message,
    ) {}

    public function broadcastOn(): Channel
    {
        return new PrivateChannel('support-thread.'.$this->message->thread_id);
    }

    public function broadcastAs(): string
    {
        return 'message.sent';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->message->id,
            'thread_id' => $this->message->thread_id,
            'direction' => $this->message->direction,
            'body' => $this->message->body,
            'created_at' => $this->message->created_at?->toIso8601String(),
        ];
    }
}
