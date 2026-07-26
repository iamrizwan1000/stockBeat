<?php

namespace App\Actions\Products;

use App\Models\Product;
use App\Support\Connections\ActionResult;
use App\Support\Connections\ChannelAdapterManager;
use Illuminate\Validation\ValidationException;

/**
 * Pushes a new stock quantity to the product's platform (Plan §4.13),
 * delegated to its `ChannelAdapter` — server-enforced against
 * `capabilities()` rather than trusting that the mobile app only shows the
 * control when supported, same convention as `FulfillOrderAction`.
 */
class UpdateProductStockAction
{
    public function __construct(
        private readonly ChannelAdapterManager $adapters,
    ) {}

    public function handle(Product $product, int $quantity): ActionResult
    {
        $adapter = $this->adapters->driver($product->connection->platform);

        if (! $adapter->capabilities()->inventoryUpdate) {
            throw ValidationException::withMessages([
                'product' => 'This channel doesn\'t support updating stock from here.',
            ]);
        }

        return $adapter->updateInventory($product, $quantity);
    }
}
