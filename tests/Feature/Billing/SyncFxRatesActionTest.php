<?php

use App\Actions\Billing\SyncFxRatesAction;
use App\Models\FxRate;
use App\Models\Order;
use App\Models\StoreConnection;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

test('it fetches real rates for every base currency in use against every currency orders arrive in', function () {
    Http::fake([
        'api.frankfurter.dev/*base=USD*' => Http::response(['date' => '2026-07-16', 'base' => 'USD', 'rates' => ['GBP' => 0.75, 'AUD' => 1.5]]),
    ]);

    $owner = User::factory()->create(['base_currency' => 'USD']);
    $team = Team::factory()->create(['owner_id' => $owner->id]);
    $connection = StoreConnection::factory()->create(['team_id' => $team->id]);
    Order::factory()->create(['team_id' => $team->id, 'connection_id' => $connection->id, 'currency' => 'GBP']);
    Order::factory()->create(['team_id' => $team->id, 'connection_id' => $connection->id, 'currency' => 'AUD', 'external_id' => 'x2']);

    $count = app(SyncFxRatesAction::class)->handle();

    expect($count)->toBe(2);
    expect(FxRate::query()->where('base', 'USD')->where('quote', 'GBP')->whereDate('date', '2026-07-16')->value('rate'))->toBe(0.75);
    expect(FxRate::query()->where('base', 'USD')->where('quote', 'AUD')->value('rate'))->toBe(1.5);
});

test('a failed API response is skipped without throwing', function () {
    Http::fake(['api.frankfurter.dev/*' => Http::response('', 500)]);

    $owner = User::factory()->create(['base_currency' => 'USD']);
    $team = Team::factory()->create(['owner_id' => $owner->id]);
    $connection = StoreConnection::factory()->create(['team_id' => $team->id]);
    Order::factory()->create(['team_id' => $team->id, 'connection_id' => $connection->id, 'currency' => 'GBP']);

    $count = app(SyncFxRatesAction::class)->handle();

    expect($count)->toBe(0);
    expect(FxRate::query()->count())->toBe(0);
});

test('nothing is synced when there is no second currency to convert against', function () {
    $owner = User::factory()->create(['base_currency' => 'USD']);
    Team::factory()->create(['owner_id' => $owner->id]);

    $count = app(SyncFxRatesAction::class)->handle();

    expect($count)->toBe(0);
});
