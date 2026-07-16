<?php

namespace App\Actions\Notifications;

use App\Models\Order;

class AutoTagAction
{
    public function handle(Order $order, string $tag): string
    {
        $tags = $order->tags ?? [];

        if (in_array($tag, $tags, true)) {
            return 'already_tagged';
        }

        $tags[] = $tag;
        $order->update(['tags' => $tags]);

        return 'tagged';
    }
}
