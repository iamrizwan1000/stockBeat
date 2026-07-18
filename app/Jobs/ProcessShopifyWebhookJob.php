<?php

namespace App\Jobs;

use App\Actions\Orders\IngestOrderAction;
use App\Actions\Rules\CheckLowStockAction;
use App\Jobs\Concerns\ThrottlesPerStoreConnection;
use App\Models\Order;
use App\Models\Rule;
use App\Models\StoreConnection;
use App\Support\Connections\Adapters\ShopifyAdapter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * The DB-mutating side of a verified Shopify webhook, moved off the
 * request/response cycle onto the `ingest` queue — same pattern as
 * `ProcessWooWebhookJob`. Handles payload shapes Woo doesn't have:
 * `refunds/create` (a refund object, not a full order), `app/uninstalled`
 * (disconnects the store, per Plan §17.2's "mark disconnected immediately,
 * stop billing-relevant counting"), and `inventory_levels/update` (feeds
 * the `low_stock` trigger, Plan §7.1).
 */
class ProcessShopifyWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, ThrottlesPerStoreConnection;

    /**
     * @param  array<string, mixed>  $parsed
     */
    public function __construct(
        public readonly int $connectionId,
        public readonly array $parsed,
    ) {}

    public function handle(IngestOrderAction $ingestOrder, ShopifyAdapter $shopifyAdapter, CheckLowStockAction $checkLowStock): void
    {
        $connection = StoreConnection::query()->find($this->connectionId);

        if ($connection === null) {
            return;
        }

        if ($this->parsed['type'] === 'app/uninstalled') {
            $connection->update(['status' => StoreConnection::STATUS_DISCONNECTED]);

            return;
        }

        // Paused by a downgrade freeze (Plan §6.4) — same guard as Woo's job.
        if ($connection->status === StoreConnection::STATUS_PAUSED) {
            return;
        }

        if ($this->parsed['type'] === 'inventory_levels/update') {
            $inventoryItemId = $this->parsed['inventory_item_id'] ?? null;

            if ($inventoryItemId !== null) {
                $product = $shopifyAdapter->syncInventoryLevel($connection, (string) $inventoryItemId, $this->parsed['available'] ?? null);

                if ($product !== null) {
                    $checkLowStock->handle($product);
                }
            }

            return;
        }

        if ($this->parsed['type'] === 'refunds/create') {
            if ($this->parsed['external_id'] !== null) {
                $order = Order::query()
                    ->where('connection_id', $connection->id)
                    ->where('external_id', $this->parsed['external_id'])
                    ->first();

                if ($order !== null && $order->status !== Order::STATUS_REFUNDED) {
                    $order->update([
                        'status' => Order::STATUS_REFUNDED,
                        'payment_status' => Order::PAYMENT_REFUNDED,
                        'check_at' => null,
                    ]);
                    RuleEvaluationJob::dispatch($order->id, Rule::TRIGGER_REFUND_SPIKE)->afterCommit();
                }
            }

            return;
        }

        if ($this->parsed['order'] !== null) {
            $ingestOrder->handle($connection, $this->parsed['order']);
        }
    }
}
