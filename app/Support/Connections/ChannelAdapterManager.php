<?php

namespace App\Support\Connections;

use App\Contracts\ChannelAdapter;
use App\Support\Connections\Adapters\AmazonAdapter;
use App\Support\Connections\Adapters\EbayAdapter;
use App\Support\Connections\Adapters\EtsyAdapter;
use App\Support\Connections\Adapters\ShopifyAdapter;
use App\Support\Connections\Adapters\WooCommerceAdapter;
use Illuminate\Support\Manager;
use RuntimeException;

/**
 * Resolves the ChannelAdapter for a given platform key (Plan §8.3). Driver
 * names match `store_connections.platform` values exactly.
 */
class ChannelAdapterManager extends Manager
{
    public function getDefaultDriver(): string
    {
        throw new RuntimeException('No default channel adapter — a platform must be specified.');
    }

    protected function createShopifyDriver(): ChannelAdapter
    {
        return $this->container->make(ShopifyAdapter::class);
    }

    protected function createWooDriver(): ChannelAdapter
    {
        return $this->container->make(WooCommerceAdapter::class);
    }

    protected function createEbayDriver(): ChannelAdapter
    {
        return $this->container->make(EbayAdapter::class);
    }

    protected function createEtsyDriver(): ChannelAdapter
    {
        return $this->container->make(EtsyAdapter::class);
    }

    protected function createAmazonDriver(): ChannelAdapter
    {
        return new AmazonAdapter;
    }
}
