<?php

use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PlanSeeder::class);
});

test('a suspended team member is rejected by the mobile API', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create(['owner_id' => $owner->id]);
    $member = User::factory()->create();
    TeamMember::factory()->create(['team_id' => $team->id, 'user_id' => $member->id, 'role' => TeamMember::ROLE_AGENT, 'suspended_at' => now()]);

    Sanctum::actingAs($member);

    test()->getJson('/api/v1/me')->assertForbidden();
});

test('a non-suspended team member is unaffected', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create(['owner_id' => $owner->id]);
    $member = User::factory()->create();
    TeamMember::factory()->create(['team_id' => $team->id, 'user_id' => $member->id, 'role' => TeamMember::ROLE_AGENT, 'suspended_at' => null]);

    Sanctum::actingAs($member);

    test()->getJson('/api/v1/me')->assertOk();
});
