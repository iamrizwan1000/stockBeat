<?php

use App\Models\Review;
use App\Models\StoreConnection;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function onboardedReviewUser(): User
{
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    test()->postJson('/api/v1/profile/setup', [
        'name' => 'Jamie Seller',
        'sells_on' => ['ebay'],
    ])->assertOk();

    return $user->fresh();
}

test('review endpoints require authentication', function () {
    test()->getJson('/api/v1/reviews')->assertUnauthorized();
});

test('a seller can list their own reviews, newest first', function () {
    $user = onboardedReviewUser();
    $team = $user->currentTeam();
    $connection = StoreConnection::factory()->create(['team_id' => $team->id]);

    Review::factory()->create(['team_id' => $team->id, 'connection_id' => $connection->id, 'reviewed_at' => now()->subDay()]);
    Review::factory()->create(['team_id' => $team->id, 'connection_id' => $connection->id, 'reviewed_at' => now()]);

    test()->getJson('/api/v1/reviews')->assertOk()->assertJsonCount(2, 'data.reviews');
});

test('a seller can reply to an eBay review and it pushes to the real Commerce Feedback API', function () {
    Http::fake(['api.sandbox.ebay.com/commerce/feedback/v1/respond_to_feedback' => Http::response([], 200)]);
    config(['services.ebay.env' => 'sandbox']);

    $user = onboardedReviewUser();
    $team = $user->currentTeam();
    $connection = StoreConnection::factory()->create([
        'team_id' => $team->id,
        'platform' => StoreConnection::PLATFORM_EBAY,
        'credentials' => ['access_token' => 'fake-token'],
    ]);
    $review = Review::factory()->create([
        'team_id' => $team->id,
        'connection_id' => $connection->id,
        'external_id' => 'fb-123',
        'reviewer_name' => 'buyer99',
    ]);

    $response = test()->postJson("/api/v1/reviews/{$review->id}/reply", ['body' => 'Sorry, replacement shipped today.']);

    $response->assertOk();

    Http::assertSent(fn ($request) => str_contains($request->url(), 'respond_to_feedback')
        && ($request['feedbackId'] ?? null) === 'fb-123'
        && ($request['recipientUserId'] ?? null) === 'buyer99');
});

test('replying to a review on a channel without reply support returns a clear error', function () {
    $user = onboardedReviewUser();
    $team = $user->currentTeam();
    $connection = StoreConnection::factory()->create(['team_id' => $team->id, 'platform' => StoreConnection::PLATFORM_WOO]);
    $review = Review::factory()->create(['team_id' => $team->id, 'connection_id' => $connection->id]);

    test()->postJson("/api/v1/reviews/{$review->id}/reply", ['body' => 'Thanks for the feedback.'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('review');
});

test('a seller cannot reply to another team\'s review', function () {
    onboardedReviewUser();
    $otherReview = Review::factory()->create();

    test()->postJson("/api/v1/reviews/{$otherReview->id}/reply", ['body' => 'Thanks.'])
        ->assertNotFound();
});

test('a viewer role cannot reply to a review', function () {
    $user = onboardedReviewUser();
    $team = $user->currentTeam();
    $connection = StoreConnection::factory()->create(['team_id' => $team->id, 'platform' => StoreConnection::PLATFORM_EBAY]);
    $review = Review::factory()->create(['team_id' => $team->id, 'connection_id' => $connection->id]);

    $viewer = User::factory()->create();
    TeamMember::factory()->create([
        'team_id' => $team->id,
        'user_id' => $viewer->id,
        'role' => TeamMember::ROLE_VIEWER,
    ]);
    Sanctum::actingAs($viewer);

    test()->postJson("/api/v1/reviews/{$review->id}/reply", ['body' => 'Thanks.'])->assertForbidden();
});

test('an empty reply body is rejected', function () {
    $user = onboardedReviewUser();
    $team = $user->currentTeam();
    $connection = StoreConnection::factory()->create(['team_id' => $team->id, 'platform' => StoreConnection::PLATFORM_EBAY]);
    $review = Review::factory()->create(['team_id' => $team->id, 'connection_id' => $connection->id]);

    test()->postJson("/api/v1/reviews/{$review->id}/reply", ['body' => ''])
        ->assertStatus(422);
});
