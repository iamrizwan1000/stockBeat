<?php

use App\Actions\Notifications\SendOrderPushWithStormProtectionAction;
use App\Models\Device;
use App\Models\Notification;
use App\Models\Order;
use App\Models\PushStormWindow;
use App\Models\StoreConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Kreait\Firebase\Contract\Messaging;

uses(RefreshDatabase::class);

test('the first orders within the window each get an individual push', function () {
    $user = User::factory()->create();
    Device::factory()->create(['user_id' => $user->id, 'push_token' => 'tok']);

    $messaging = Mockery::mock(Messaging::class);
    $messaging->shouldReceive('send')->times(5)->andReturn([]);
    app()->instance(Messaging::class, $messaging);

    $action = app(SendOrderPushWithStormProtectionAction::class);

    foreach (range(1, 5) as $i) {
        $order = Order::factory()->create(['total' => 10, 'total_base_currency' => 10]);
        $status = $action->handle($user, $order, 'New order', "Order #{$i}");
        expect($status)->toBe('sent');
    }

    expect(Notification::query()->where('user_id', $user->id)->count())->toBe(5);
});

test('crossing the threshold collapses into a single bundled push and suppresses the rest', function () {
    $user = User::factory()->create();
    Device::factory()->create(['user_id' => $user->id, 'push_token' => 'tok']);

    $messaging = Mockery::mock(Messaging::class);
    // 5 individual sends (orders 1-5) + 1 bundled summary send (order 6). Orders 7+ send nothing.
    $messaging->shouldReceive('send')->times(6)->andReturn([]);
    app()->instance(Messaging::class, $messaging);

    $action = app(SendOrderPushWithStormProtectionAction::class);

    $statuses = [];
    foreach (range(1, 8) as $i) {
        $order = Order::factory()->create(['total' => 100, 'total_base_currency' => 100]);
        $statuses[] = $action->handle($user, $order, 'New order', "Order #{$i}");
    }

    expect($statuses)->toBe(['sent', 'sent', 'sent', 'sent', 'sent', 'sent', 'bundled_suppressed', 'bundled_suppressed']);
    // Every order still gets an in-app record, plus one extra record for the
    // bundled summary itself once it fires — the center is a record of what fired.
    expect(Notification::query()->where('user_id', $user->id)->count())->toBe(9);

    $window = PushStormWindow::query()->where('user_id', $user->id)->firstOrFail();
    expect($window->order_count)->toBe(8);
    expect((float) $window->revenue_total)->toBe(800.0);
    expect($window->bundle_sent_at)->not->toBeNull();
});

test('a muted store connection suppresses the order push but still logs it', function () {
    $user = User::factory()->create();
    Device::factory()->create(['user_id' => $user->id, 'push_token' => 'tok']);
    $connection = StoreConnection::factory()->create(['notifications_muted' => true]);

    $messaging = Mockery::mock(Messaging::class);
    $messaging->shouldNotReceive('send');
    app()->instance(Messaging::class, $messaging);

    $order = Order::factory()->create(['connection_id' => $connection->id, 'total' => 10, 'total_base_currency' => 10]);
    $status = app(SendOrderPushWithStormProtectionAction::class)->handle($user, $order, 'New order', 'Order #1', connection: $connection);

    expect($status)->toBe('muted_by_store');
    expect(Notification::query()->where('user_id', $user->id)->count())->toBe(1);
});

test('an expired window resets and sends individually again', function () {
    $user = User::factory()->create();
    Device::factory()->create(['user_id' => $user->id, 'push_token' => 'tok']);
    PushStormWindow::factory()->create([
        'user_id' => $user->id,
        'window_started_at' => now()->subMinutes(30),
        'order_count' => 20,
        'revenue_total' => 5000,
        'bundle_sent_at' => now()->subMinutes(25),
    ]);

    $messaging = Mockery::mock(Messaging::class);
    $messaging->shouldReceive('send')->once()->andReturn([]);
    app()->instance(Messaging::class, $messaging);

    $order = Order::factory()->create(['total' => 50, 'total_base_currency' => 50]);
    $status = app(SendOrderPushWithStormProtectionAction::class)->handle($user, $order, 'New order', 'Order');

    expect($status)->toBe('sent');
    $window = PushStormWindow::query()->where('user_id', $user->id)->firstOrFail();
    expect($window->order_count)->toBe(1);
    expect((float) $window->revenue_total)->toBe(50.0);
    expect($window->bundle_sent_at)->toBeNull();
});
