<?php

namespace App\Actions\Orders;

use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\OrderNote;
use App\Models\User;
use Illuminate\Support\Str;

class AddOrderNoteAction
{
    public function handle(Order $order, User $user, string $body): OrderNote
    {
        $note = OrderNote::query()->create([
            'order_id' => $order->id,
            'user_id' => $user->id,
            'body' => $body,
        ]);

        OrderEvent::query()->create([
            'order_id' => $order->id,
            'type' => OrderEvent::TYPE_NOTE_ADDED,
            'payload' => ['user_id' => $user->id, 'excerpt' => Str::limit($body, 140)],
            'occurred_at' => now(),
        ]);

        return $note;
    }
}
