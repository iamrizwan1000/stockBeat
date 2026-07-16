<?php

namespace App\Jobs;

use App\Actions\Orders\IngestOrderAction;
use App\Jobs\Concerns\ThrottlesPerStoreConnection;
use App\Models\Order;
use App\Models\Rule;
use App\Models\StoreConnection;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * The actual DB-mutating side of a verified Woo webhook, moved off the
 * request/response cycle onto the `ingest` queue (Plan §15.1) — signature
 * verification (`ChannelAdapter::parseWebhook()`) still happens synchronously
 * in `WebhookController` since it needs the raw `Request`, but once verified
 * there's no reason to make WooCommerce wait on our DB before it gets a 200.
 */
class ProcessWooWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, ThrottlesPerStoreConnection;

    /**
     * @param  array<string, mixed>  $parsed
     */
    public function __construct(
        public readonly int $connectionId,
        public readonly array $parsed,
    ) {}

    public function handle(IngestOrderAction $ingestOrder): void
    {
        $connection = StoreConnection::query()->find($this->connectionId);

        if ($connection === null) {
            return;
        }

        // Paused by a downgrade freeze (Plan §6.4: "paused stores stop
        // syncing — resume + backfill on upgrade"). The reconciliation
        // poller already skips paused connections (§7.2's polling commands
        // filter on `status=active`); webhooks need the same guard since
        // they arrive independently of polling.
        if ($connection->status === StoreConnection::STATUS_PAUSED) {
            return;
        }

        if ($this->parsed['type'] === 'order.deleted') {
            if ($this->parsed['external_id'] !== null) {
                $order = Order::query()
                    ->where('connection_id', $connection->id)
                    ->where('external_id', $this->parsed['external_id'])
                    ->first();

                if ($order !== null && $order->status !== Order::STATUS_CANCELLED) {
                    $order->update(['status' => Order::STATUS_CANCELLED, 'check_at' => null]);
                    RuleEvaluationJob::dispatch($order->id, Rule::TRIGGER_ORDER_CANCELLED)->afterCommit();
                }
            }

            return;
        }

        if ($this->parsed['order'] !== null) {
            $ingestOrder->handle($connection, $this->parsed['order']);
        }
    }
}
