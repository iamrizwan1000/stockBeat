<?php

namespace App\Actions\Inbox;

use App\Models\InboxThread;

/**
 * Plan §4.5: reply templates with `{customer_name}`, `{order_number}`,
 * `{tracking}` variables.
 */
class RenderReplyTemplateAction
{
    public function handle(string $bodyWithVariables, InboxThread $thread): string
    {
        $order = $thread->order;

        return strtr($bodyWithVariables, [
            '{customer_name}' => $thread->customer_name ?? 'there',
            '{order_number}' => $order !== null ? $order->order_number : '',
            '{tracking}' => $order !== null ? $order->tracking_number : '',
        ]);
    }
}
