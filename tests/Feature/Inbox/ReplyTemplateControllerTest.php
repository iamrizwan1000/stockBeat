<?php

use App\Models\ReplyTemplate;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PlanSeeder::class);
});

function onboardedTemplateOwner(): User
{
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    test()->postJson('/api/v1/profile/setup', [
        'name' => 'Jamie Seller',
        'sells_on' => ['woo'],
    ])->assertOk();

    return $user->fresh();
}

test('listing reply templates requires authentication', function () {
    test()->getJson('/api/v1/reply-templates')->assertUnauthorized();
});

test('a seller only sees their own teams reply templates', function () {
    $userA = onboardedTemplateOwner();
    ReplyTemplate::factory()->create(['team_id' => $userA->ownedTeam->id, 'name' => 'Shipped']);

    $userB = onboardedTemplateOwner();
    ReplyTemplate::factory()->create(['team_id' => $userB->ownedTeam->id, 'name' => 'Delay']);

    Sanctum::actingAs($userA);
    test()->getJson('/api/v1/reply-templates')
        ->assertOk()
        ->assertJsonCount(1, 'data.templates')
        ->assertJsonPath('data.templates.0.name', 'Shipped');
});

test('creating a reply template stores it under the callers team', function () {
    $user = onboardedTemplateOwner();

    test()->postJson('/api/v1/reply-templates', [
        'name' => 'Shipped',
        'body_with_variables' => 'Hi {customer_name}, shipped!',
    ])->assertCreated()->assertJsonPath('data.template.name', 'Shipped');

    expect(ReplyTemplate::query()->where('team_id', $user->ownedTeam->id)->count())->toBe(1);
});

test('updating another teams reply template is not found', function () {
    onboardedTemplateOwner();
    $other = ReplyTemplate::factory()->create();

    test()->putJson("/api/v1/reply-templates/{$other->id}", [
        'name' => 'Hacked',
        'body_with_variables' => 'x',
    ])->assertNotFound();
});

test('deleting a reply template removes it', function () {
    $user = onboardedTemplateOwner();
    $template = ReplyTemplate::factory()->create(['team_id' => $user->ownedTeam->id]);

    test()->deleteJson("/api/v1/reply-templates/{$template->id}")->assertOk();

    expect(ReplyTemplate::query()->find($template->id))->toBeNull();
});
