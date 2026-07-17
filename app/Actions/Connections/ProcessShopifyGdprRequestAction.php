<?php

namespace App\Actions\Connections;

use App\Models\Order;
use App\Models\StoreConnection;
use Illuminate\Support\Facades\Log;

/**
 * Handles Shopify's three mandatory compliance webhooks (Plan §7.1/§17.2,
 * registered via `shopify.app.toml`'s `compliance_topics`). Arrives at a
 * single global endpoint (not per-connection like order webhooks), so the
 * connection is resolved by `shop_domain` via the same fingerprint hash
 * `ComputeStoreConnectionFingerprintAction` already computes on connect —
 * `store_connections.credentials` is encrypted, so it can't be queried
 * directly at the SQL level (same reason `orders.customer_email` stays
 * unencrypted elsewhere: encrypted columns aren't queryable).
 */
class ProcessShopifyGdprRequestAction
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(string $topic, array $payload): void
    {
        $shopDomain = (string) ($payload['shop_domain'] ?? '');

        if ($shopDomain === '') {
            return;
        }

        $fingerprint = hash('sha256', 'shopify|'.strtolower(rtrim($shopDomain, '/')));

        $connection = StoreConnection::query()
            ->where('platform', StoreConnection::PLATFORM_SHOPIFY)
            ->where('fingerprint', $fingerprint)
            ->first();

        if ($connection === null) {
            // Shop never connected (or already disconnected) — nothing to
            // redact or delete. Still a valid, expected outcome.
            return;
        }

        match ($topic) {
            'shop/redact' => $connection->delete(), // cascades to orders/items/events/notes (FK cascadeOnDelete)
            'customers/redact' => $this->redactCustomer($connection, $payload),
            // customers/data_request: Shopify expects the data delivered
            // "directly to the store owner" — no self-service delivery
            // mechanism exists yet (no admin-facing request queue), so this
            // is an honest gap: we acknowledge within the required window
            // and log the request rather than fabricate a delivery flow.
            default => Log::info('Shopify customers/data_request received', [
                'connection_id' => $connection->id,
                'payload' => $payload,
            ]),
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function redactCustomer(StoreConnection $connection, array $payload): void
    {
        $email = $payload['customer']['email'] ?? null;
        /** @var array<int, int|string> $orderIds */
        $orderIds = array_map('strval', $payload['orders_to_redact'] ?? []);

        $query = Order::query()->where('connection_id', $connection->id);

        if ($orderIds !== []) {
            $query->whereIn('external_id', $orderIds);
        } elseif (is_string($email) && $email !== '') {
            $query->where('customer_email', $email);
        } else {
            return;
        }

        $query->update([
            'customer_name' => null,
            'customer_email' => null,
            'shipping_address' => null,
        ]);
    }
}
