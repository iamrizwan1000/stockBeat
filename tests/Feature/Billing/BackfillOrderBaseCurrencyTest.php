<?php

use App\Models\FxRate;
use App\Models\Order;
use App\Models\StoreConnection;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

test('orders stuck null get backfilled once a matching fx rate exists', function () {
    $owner = User::factory()->create(['base_currency' => 'USD']);
    $team = Team::factory()->create(['owner_id' => $owner->id]);
    $connection = StoreConnection::factory()->create(['team_id' => $team->id]);

    $order = Order::factory()->create([
        'team_id' => $team->id,
        'connection_id' => $connection->id,
        'currency' => 'GBP',
        'total' => 80,
        'total_base_currency' => null,
        'placed_at' => now(),
    ]);

    Artisan::call('orders:backfill-base-currency');
    expect($order->fresh()->total_base_currency)->toBeNull();

    FxRate::factory()->create(['base' => 'USD', 'quote' => 'GBP', 'rate' => 0.75, 'date' => now()->toDateString()]);

    Artisan::call('orders:backfill-base-currency');
    expect($order->fresh()->total_base_currency)->toBe(106.67);
});

test('orders whose currency already matches the base currency are left alone', function () {
    $owner = User::factory()->create(['base_currency' => 'USD']);
    $team = Team::factory()->create(['owner_id' => $owner->id]);
    $connection = StoreConnection::factory()->create(['team_id' => $team->id]);

    $order = Order::factory()->create([
        'team_id' => $team->id,
        'connection_id' => $connection->id,
        'currency' => 'USD',
        'total' => 50,
        'total_base_currency' => null,
    ]);

    Artisan::call('orders:backfill-base-currency');

    expect($order->fresh()->total_base_currency)->toBeNull();
});
