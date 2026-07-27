<?php

namespace App\Actions\Orders;

use App\Models\Order;
use App\Models\Team;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Bulk-tags a batch of orders (Plan §4.17) — appends `tag` to each order's
 * existing tag list (deduped) rather than replacing it. A plain passthrough
 * to `UpdateOrderTagsAction::handle($order, [$tag])` would *replace* the
 * whole list per that action's single-order-edit contract, silently wiping
 * unrelated tags off every order in the batch — clearly worse than not
 * having the feature. Team ownership is checked atomically up front, same
 * as `BulkUpdateCostPricesAction`; unlike bulk-cancel, tagging is a plain DB
 * write that can never fail once ownership is confirmed, so there's no
 * per-order result to report — every id in the batch always succeeds.
 */
class BulkTagOrdersAction
{
    public function __construct(
        private readonly UpdateOrderTagsAction $updateTags,
    ) {}

    /**
     * @param  array<int, int>  $ids
     * @return Collection<int, Order>
     */
    public function handle(Team $team, array $ids, string $tag): Collection
    {
        $ids = array_values(array_unique($ids));

        $orders = Order::query()->where('team_id', $team->id)->whereIn('id', $ids)->get()->keyBy('id');

        if ($orders->count() !== count($ids)) {
            throw ValidationException::withMessages([
                'ids' => ['One or more orders do not belong to your team.'],
            ]);
        }

        foreach ($ids as $id) {
            $order = $orders[$id];
            $this->updateTags->handle($order, [...($order->tags ?? []), $tag]);
        }

        return $orders->fresh();
    }
}
