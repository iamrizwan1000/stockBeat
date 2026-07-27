<?php

use App\Models\SavedOrderFilter;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function onboardedSavedFilterUser(): User
{
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    test()->postJson('/api/v1/profile/setup', [
        'name' => 'Jamie Seller',
        'sells_on' => ['woo'],
    ])->assertOk();

    return $user->fresh();
}

test('saved order filter endpoints require authentication', function () {
    test()->getJson('/api/v1/order-filters')->assertUnauthorized();
    test()->postJson('/api/v1/order-filters', ['name' => 'x', 'filters' => []])->assertUnauthorized();
});

test('a seller can save, list, update, and delete an order filter — free on every plan', function () {
    $user = onboardedSavedFilterUser();

    $create = test()->postJson('/api/v1/order-filters', [
        'name' => 'Unfulfilled Shopify',
        'filters' => ['channel' => 'shopify', 'status' => 'unfulfilled'],
    ]);
    $create->assertCreated()->assertJsonPath('data.filter.name', 'Unfulfilled Shopify');
    $id = $create->json('data.filter.id');

    test()->getJson('/api/v1/order-filters')
        ->assertOk()
        ->assertJsonCount(1, 'data.filters');

    test()->putJson("/api/v1/order-filters/{$id}", [
        'name' => 'Unfulfilled Woo',
        'filters' => ['channel' => 'woo', 'status' => 'unfulfilled'],
    ])->assertOk()->assertJsonPath('data.filter.filters.channel', 'woo');

    test()->deleteJson("/api/v1/order-filters/{$id}")->assertOk();
    test()->getJson('/api/v1/order-filters')->assertOk()->assertJsonCount(0, 'data.filters');
});

test('unrecognized filter keys are silently dropped, not stored', function () {
    onboardedSavedFilterUser();

    $response = test()->postJson('/api/v1/order-filters', [
        'name' => 'Weird',
        'filters' => ['status' => 'unfulfilled', 'not_a_real_field' => 'whatever'],
    ]);

    $response->assertCreated();
    expect($response->json('data.filter.filters'))->toBe(['status' => 'unfulfilled']);
});

test('a seller cannot update or delete another team\'s saved filter', function () {
    onboardedSavedFilterUser();
    $otherFilter = SavedOrderFilter::factory()->create();

    test()->putJson("/api/v1/order-filters/{$otherFilter->id}", ['name' => 'x', 'filters' => ['status' => 'new']])->assertNotFound();
    test()->deleteJson("/api/v1/order-filters/{$otherFilter->id}")->assertNotFound();
});

test('a viewer role cannot create a saved filter but can list them', function () {
    $user = onboardedSavedFilterUser();
    $team = $user->currentTeam();
    SavedOrderFilter::factory()->create(['team_id' => $team->id]);

    $viewer = User::factory()->create();
    TeamMember::factory()->create(['team_id' => $team->id, 'user_id' => $viewer->id, 'role' => TeamMember::ROLE_VIEWER]);
    Sanctum::actingAs($viewer);

    test()->getJson('/api/v1/order-filters')->assertOk()->assertJsonCount(1, 'data.filters');
    test()->postJson('/api/v1/order-filters', ['name' => 'x', 'filters' => []])->assertForbidden();
});
