<?php

namespace App\Actions\Orders;

use App\Models\Order;
use App\Models\Team;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * Bulk packing slips (Plan §4.22, added 2026-07-27) — one multi-page PDF
 * covering every requested order, not a zip of separate files, so it's one
 * thing to share from the native share sheet. Reuses the same per-order
 * markup as the single-order `GeneratePackingSlipAction`/
 * `orders.packing-slip` view (still deliberately price-free — a warehouse
 * packing document), just looped with a page break between orders.
 *
 * Team ownership is checked atomically up front, same as
 * `BulkCancelOrdersAction`/`BulkTagOrdersAction` — any id not belonging to
 * the team 422s the whole call before any rendering happens. Unlike those
 * two, there's no per-order failure mode to report here: this is a pure
 * read/render over data that's already there, so every requested id that
 * passes the ownership check always succeeds.
 */
class GenerateBulkPackingSlipsAction
{
    /**
     * @param  array<int, int>  $ids
     */
    public function handle(Team $team, array $ids): Response
    {
        $ids = array_values(array_unique($ids));

        $orders = Order::query()
            ->with(['items', 'connection'])
            ->where('team_id', $team->id)
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        if ($orders->count() !== count($ids)) {
            throw ValidationException::withMessages([
                'ids' => ['One or more orders do not belong to your team.'],
            ]);
        }

        // Preserve the caller's requested order rather than whatever order
        // the query happened to return them in.
        $ordered = collect($ids)->map(fn (int $id) => $orders[$id]);

        return Pdf::loadView('orders.packing-slips-bulk', ['orders' => $ordered])
            ->stream('packing-slips-'.now()->format('Y-m-d').'.pdf');
    }
}
