<?php

use App\Jobs\RuleEvaluationJob;
use App\Models\Order;
use App\Models\Rule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

test('orders with a due check_at get both time-based triggers dispatched', function () {
    Queue::fake();

    $due = Order::factory()->create(['check_at' => now()->subHour()]);
    $notYetDue = Order::factory()->create(['check_at' => now()->addHour()]);
    $terminal = Order::factory()->create(['check_at' => null]);

    Artisan::call('orders:check-deadlines');

    Queue::assertPushed(RuleEvaluationJob::class, 2);
    Queue::assertPushed(fn (RuleEvaluationJob $job) => $job->orderId === $due->id && $job->trigger === Rule::TRIGGER_UNFULFILLED_AFTER_X);
    Queue::assertPushed(fn (RuleEvaluationJob $job) => $job->orderId === $due->id && $job->trigger === Rule::TRIGGER_SHIP_BY_DEADLINE);
    Queue::assertNotPushed(fn (RuleEvaluationJob $job) => $job->orderId === $notYetDue->id);
    Queue::assertNotPushed(fn (RuleEvaluationJob $job) => $job->orderId === $terminal->id);
});
