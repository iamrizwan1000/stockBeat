<?php

use App\Mail\TeamInviteMail;
use App\Models\Order;
use App\Models\Plan;
use App\Models\StoreConnection;
use App\Models\TeamInvite;
use App\Models\TeamMember;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PlanSeeder::class);
});

function onboardedTeamOwner(): User
{
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    test()->postJson('/api/v1/profile/setup', [
        'name' => 'Jamie Seller',
        'sells_on' => ['woo'],
    ])->assertOk();

    return $user->fresh();
}

test('the team roster requires authentication', function () {
    test()->getJson('/api/v1/team')->assertUnauthorized();
});

test('the owner can view the team roster including pending invites', function () {
    $owner = onboardedTeamOwner();

    test()->postJson('/api/v1/team/invite', ['email' => 'agent@example.com', 'role' => 'agent'])
        ->assertCreated();

    test()->getJson('/api/v1/team')
        ->assertOk()
        ->assertJsonCount(1, 'data.members')
        ->assertJsonPath('data.members.0.role', TeamMember::ROLE_OWNER)
        ->assertJsonCount(1, 'data.pending_invites')
        ->assertJsonPath('data.pending_invites.0.email', 'agent@example.com');
});

test('inviting a member sends a real invite email and creates a pending invite', function () {
    Mail::fake();
    $owner = onboardedTeamOwner();

    test()->postJson('/api/v1/team/invite', ['email' => 'manager@example.com', 'role' => 'manager'])
        ->assertCreated()
        ->assertJsonPath('data.invite.email', 'manager@example.com')
        ->assertJsonPath('data.invite.status', TeamInvite::STATUS_PENDING);

    Mail::assertQueued(TeamInviteMail::class, fn ($mail) => $mail->hasTo('manager@example.com'));

    expect(TeamInvite::query()->where('team_id', $owner->currentTeam()->id)->count())->toBe(1);
});

test('inviting someone already on the team is rejected', function () {
    $owner = onboardedTeamOwner();

    test()->postJson('/api/v1/team/invite', ['email' => $owner->email, 'role' => 'manager'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('email');
});

test('a duplicate pending invite for the same email is rejected', function () {
    $owner = onboardedTeamOwner();

    test()->postJson('/api/v1/team/invite', ['email' => 'dup@example.com', 'role' => 'viewer'])->assertCreated();

    test()->postJson('/api/v1/team/invite', ['email' => 'dup@example.com', 'role' => 'viewer'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('email');
});

test('the team_seats plan limit blocks invites once reached', function () {
    $owner = onboardedTeamOwner();
    // Trial defaults to Premium (10 seats) — pin to Pro (3 seats) explicitly
    // so this test doesn't depend on which tier the trial happens to grant.
    $owner->currentTeam()->subscription->update(['plan_key' => Plan::PRO]);

    test()->postJson('/api/v1/team/invite', ['email' => 'a@example.com', 'role' => 'agent'])->assertCreated();
    test()->postJson('/api/v1/team/invite', ['email' => 'b@example.com', 'role' => 'agent'])->assertCreated();

    test()->postJson('/api/v1/team/invite', ['email' => 'c@example.com', 'role' => 'agent'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('email');
});

test('a brand-new user with a pending invite joins as a member instead of getting their own team', function () {
    $owner = onboardedTeamOwner();
    $team = $owner->currentTeam();

    test()->postJson('/api/v1/team/invite', ['email' => 'newbie@example.com', 'role' => 'agent'])->assertCreated();

    $newUser = User::factory()->create(['email' => 'newbie@example.com']);
    Sanctum::actingAs($newUser);

    test()->postJson('/api/v1/profile/setup', ['name' => 'New Bie', 'sells_on' => ['woo']])->assertOk();

    $newUser = $newUser->fresh();
    expect($newUser->ownedTeam()->exists())->toBeFalse();
    expect($newUser->currentTeam()->id)->toBe($team->id);
    expect($newUser->currentTeamMember()->role)->toBe(TeamMember::ROLE_AGENT);

    $invite = TeamInvite::query()->where('email', 'newbie@example.com')->first();
    expect($invite->status)->toBe(TeamInvite::STATUS_ACCEPTED);

    test()->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.team.role', TeamMember::ROLE_AGENT);
});

test('a user who already belongs to a team keeps it and their invite stays pending', function () {
    // Onboards first (creating their own team) BEFORE being invited elsewhere.
    $existingOwner = User::factory()->create(['email' => 'busy@example.com']);
    Sanctum::actingAs($existingOwner);
    test()->postJson('/api/v1/profile/setup', ['name' => 'Busy Owner', 'sells_on' => ['woo']])->assertOk();
    $existingOwner = $existingOwner->fresh();
    $ownTeamId = $existingOwner->ownedTeam->id;

    onboardedTeamOwner();
    test()->postJson('/api/v1/team/invite', ['email' => 'busy@example.com', 'role' => 'viewer'])->assertCreated();

    expect($existingOwner->fresh()->currentTeam()->id)->toBe($ownTeamId);

    $invite = TeamInvite::query()->where('email', 'busy@example.com')->first();
    expect($invite->status)->toBe(TeamInvite::STATUS_PENDING);
});

test('the owner can update a member\'s role and store visibility', function () {
    $owner = onboardedTeamOwner();
    $member = TeamMember::factory()->create([
        'team_id' => $owner->currentTeam()->id,
        'role' => TeamMember::ROLE_VIEWER,
    ]);

    test()->putJson("/api/v1/team/{$member->id}", ['role' => 'manager', 'store_visibility' => [1, 2]])
        ->assertOk()
        ->assertJsonPath('data.member.role', 'manager')
        ->assertJsonPath('data.member.store_visibility', [1, 2]);
});

test('the owner\'s own role can never be changed', function () {
    $owner = onboardedTeamOwner();
    $ownerMember = $owner->currentTeamMember();

    test()->putJson("/api/v1/team/{$ownerMember->id}", ['role' => 'viewer'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('role');
});

test('updating a member from a different team 404s', function () {
    onboardedTeamOwner();
    $otherTeamMember = TeamMember::factory()->create();

    test()->putJson("/api/v1/team/{$otherTeamMember->id}", ['role' => 'viewer'])->assertNotFound();
});

test('the owner can remove a member from the team', function () {
    $owner = onboardedTeamOwner();
    $member = TeamMember::factory()->create([
        'team_id' => $owner->currentTeam()->id,
        'role' => TeamMember::ROLE_VIEWER,
    ]);

    test()->deleteJson("/api/v1/team/{$member->id}")
        ->assertOk()
        ->assertJsonPath('message', 'Team member removed.');

    expect(TeamMember::query()->find($member->id))->toBeNull();
});

test('the team owner can never be removed', function () {
    $owner = onboardedTeamOwner();
    $ownerMember = $owner->currentTeamMember();

    test()->deleteJson("/api/v1/team/{$ownerMember->id}")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('member');

    expect(TeamMember::query()->find($ownerMember->id))->not->toBeNull();
});

test('removing a member from a different team 404s', function () {
    onboardedTeamOwner();
    $otherTeamMember = TeamMember::factory()->create();

    test()->deleteJson("/api/v1/team/{$otherTeamMember->id}")->assertNotFound();

    expect(TeamMember::query()->find($otherTeamMember->id))->not->toBeNull();
});

test('an agent role cannot remove team members', function () {
    $owner = onboardedTeamOwner();
    $agentUser = User::factory()->create();
    TeamMember::factory()->create([
        'team_id' => $owner->currentTeam()->id,
        'user_id' => $agentUser->id,
        'role' => TeamMember::ROLE_AGENT,
    ]);
    $viewerMember = TeamMember::factory()->create([
        'team_id' => $owner->currentTeam()->id,
        'role' => TeamMember::ROLE_VIEWER,
    ]);

    Sanctum::actingAs($agentUser);

    test()->deleteJson("/api/v1/team/{$viewerMember->id}")->assertForbidden();
});

test('removing a member does not affect their own account, only their access to this team', function () {
    $owner = onboardedTeamOwner();
    $removedUser = User::factory()->create();
    $member = TeamMember::factory()->create([
        'team_id' => $owner->currentTeam()->id,
        'user_id' => $removedUser->id,
        'role' => TeamMember::ROLE_AGENT,
    ]);

    test()->deleteJson("/api/v1/team/{$member->id}")->assertOk();

    Sanctum::actingAs($removedUser->fresh());
    test()->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('data.needs_profile_setup', true)
        ->assertJsonPath('data.team', null);
});

test('an agent role cannot invite members or create rules but can still read', function () {
    $owner = onboardedTeamOwner();
    $agentUser = User::factory()->create();
    TeamMember::factory()->create([
        'team_id' => $owner->currentTeam()->id,
        'user_id' => $agentUser->id,
        'role' => TeamMember::ROLE_AGENT,
    ]);

    Sanctum::actingAs($agentUser);

    test()->postJson('/api/v1/team/invite', ['email' => 'blocked@example.com', 'role' => 'viewer'])
        ->assertForbidden();

    test()->postJson('/api/v1/rules', ['name' => 'x', 'trigger' => 'new_order', 'actions' => [['type' => 'push']]])
        ->assertForbidden();

    test()->getJson('/api/v1/team')->assertOk();
    test()->getJson('/api/v1/orders')->assertOk();
});

test('a member restricted to specific stores only sees orders from those stores', function () {
    $owner = onboardedTeamOwner();
    $team = $owner->currentTeam();

    $visibleConnection = StoreConnection::factory()->create(['team_id' => $team->id]);
    $hiddenConnection = StoreConnection::factory()->create(['team_id' => $team->id]);

    $visibleOrder = Order::factory()->create(['team_id' => $team->id, 'connection_id' => $visibleConnection->id]);
    $hiddenOrder = Order::factory()->create(['team_id' => $team->id, 'connection_id' => $hiddenConnection->id]);

    $viewerUser = User::factory()->create();
    TeamMember::factory()->create([
        'team_id' => $team->id,
        'user_id' => $viewerUser->id,
        'role' => TeamMember::ROLE_VIEWER,
        'store_visibility' => [$visibleConnection->id],
    ]);

    Sanctum::actingAs($viewerUser);

    test()->getJson('/api/v1/orders')
        ->assertOk()
        ->assertJsonCount(1, 'data.orders')
        ->assertJsonPath('data.orders.0.id', $visibleOrder->id);

    test()->getJson("/api/v1/orders/{$visibleOrder->id}")->assertOk();
    test()->getJson("/api/v1/orders/{$hiddenOrder->id}")->assertNotFound();
});
