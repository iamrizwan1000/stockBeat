<?php

namespace App\Actions\Orders;

use App\Models\Order;
use App\Models\Team;
use Illuminate\Validation\ValidationException;

/**
 * Bulk-cancels a batch of orders (Plan §4.17). Team ownership is checked
 * atomically up front, same as `BulkUpdateCostPricesAction` — any id not
 * belonging to the team 422s the whole call before anything happens. But
 * per-order cancellation is deliberately NOT atomic: unlike a cost-price
 * edit (a plain DB write that can never fail once ownership is confirmed),
 * cancelling genuinely can fail per-order (a channel without cancel
 * support, an adapter rejection) for reasons outside the caller's control —
 * one bad order in a batch of 20 shouldn't block the other 19.
 */
class BulkCancelOrdersAction
{
    public function __construct(
        private readonly CancelOrderAction $cancelOrder,
    ) {}

    /**
     * @param  array<int, int>  $ids
     * @return array<int, array{id: int, success: bool, error: string|null}>
     */
    public function handle(Team $team, array $ids, ?string $reason): array
    {
        $ids = array_values(array_unique($ids));

        $orders = Order::query()->where('team_id', $team->id)->whereIn('id', $ids)->get()->keyBy('id');

        if ($orders->count() !== count($ids)) {
            throw ValidationException::withMessages([
                'ids' => ['One or more orders do not belong to your team.'],
            ]);
        }

        return collect($ids)->map(function (int $id) use ($orders, $reason) {
            $order = $orders[$id];

            try {
                $result = $this->cancelOrder->handle($order, $reason);
            } catch (ValidationException $e) {
                return ['id' => $id, 'success' => false, 'error' => $e->validator->errors()->first()];
            }

            return [
                'id' => $id,
                'success' => $result->success,
                'error' => $result->success ? null : $result->message,
            ];
        })->values()->all();
    }
}
