<?php

use App\Mail\DataExportMail;
use App\Models\Order;
use App\Models\OtpCode;
use App\Models\StoreConnection;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PlanSeeder::class);
});

function onboardedAccountUser(): User
{
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    test()->postJson('/api/v1/profile/setup', ['name' => 'Jamie Seller', 'sells_on' => ['woo']])->assertOk();

    return $user->fresh();
}

test('account endpoints require authentication', function () {
    test()->postJson('/api/v1/account/data-export')->assertUnauthorized();
    test()->postJson('/api/v1/account/delete-request')->assertUnauthorized();
});

test('data export queues a real email with a JSON attachment containing the team\'s data', function () {
    Mail::fake();
    $user = onboardedAccountUser();
    $team = $user->currentTeam();
    $connection = StoreConnection::factory()->create(['team_id' => $team->id]);
    Order::factory()->create(['team_id' => $team->id, 'connection_id' => $connection->id, 'order_number' => '#EXPORT-1']);

    test()->postJson('/api/v1/account/data-export')->assertOk();

    Mail::assertQueued(DataExportMail::class, function ($mail) {
        $data = json_decode($mail->json, true);

        return $data['orders'][0]['order_number'] === '#EXPORT-1'
            && ! isset($data['store_connections'][0]['credentials']);
    });
});

test('deleting an owner\'s account soft-deletes their team, and other members lose access', function () {
    $owner = onboardedAccountUser();
    $team = $owner->currentTeam();

    $memberUser = User::factory()->create();
    TeamMember::factory()->create(['team_id' => $team->id, 'user_id' => $memberUser->id, 'role' => TeamMember::ROLE_AGENT]);

    Sanctum::actingAs($owner);
    test()->postJson('/api/v1/account/delete-request')->assertOk();

    expect(User::query()->find($owner->id))->toBeNull();
    expect(User::withTrashed()->find($owner->id))->not->toBeNull();
    expect(Team::query()->find($team->id))->toBeNull();
    expect($owner->tokens()->count())->toBe(0);

    Sanctum::actingAs($memberUser);
    expect($memberUser->fresh()->currentTeam())->toBeNull();
});

test('deleting a non-owner member\'s account leaves the team intact', function () {
    $owner = onboardedAccountUser();
    $team = $owner->currentTeam();

    $memberUser = User::factory()->create();
    TeamMember::factory()->create(['team_id' => $team->id, 'user_id' => $memberUser->id, 'role' => TeamMember::ROLE_VIEWER]);

    Sanctum::actingAs($memberUser);
    test()->postJson('/api/v1/account/delete-request')->assertOk();

    expect(Team::query()->find($team->id))->not->toBeNull();
    expect(User::query()->find($owner->id))->not->toBeNull();
});

test('re-verifying OTP for a soft-deleted account is rejected cleanly instead of crashing', function () {
    $user = onboardedAccountUser();
    $email = $user->email;

    Sanctum::actingAs($user);
    test()->postJson('/api/v1/account/delete-request')->assertOk();

    $code = '123456';
    OtpCode::query()->create([
        'email' => $email,
        'code_hash' => Hash::make($code),
        'expires_at' => now()->addMinutes(10),
    ]);

    test()->postJson('/api/v1/auth/otp/verify', ['email' => $email, 'code' => $code])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('email');
});

test('accounts:purge-deleted hard-deletes past the grace period but leaves recent ones alone', function () {
    $recentlyDeleted = User::factory()->create(['deleted_at' => now()->subDays(5)]);
    $longDeleted = User::factory()->create(['deleted_at' => now()->subDays(31)]);

    test()->artisan('accounts:purge-deleted');

    expect(User::withTrashed()->find($recentlyDeleted->id))->not->toBeNull();
    expect(User::withTrashed()->find($longDeleted->id))->toBeNull();
});
