<?php

namespace App\Actions\Orders;

use App\Models\Order;
use App\Models\OrderNote;
use App\Models\User;

class AddOrderNoteAction
{
    public function handle(Order $order, User $user, string $body): OrderNote
    {
        return OrderNote::query()->create([
            'order_id' => $order->id,
            'user_id' => $user->id,
            'body' => $body,
        ]);
    }
}
