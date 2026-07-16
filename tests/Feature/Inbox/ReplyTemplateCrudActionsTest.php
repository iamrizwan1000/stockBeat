<?php

use App\Actions\Inbox\CreateReplyTemplateAction;
use App\Actions\Inbox\DeleteReplyTemplateAction;
use App\Actions\Inbox\UpdateReplyTemplateAction;
use App\Models\ReplyTemplate;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('creating a reply template persists it under the team', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create(['owner_id' => $owner->id]);

    $template = app(CreateReplyTemplateAction::class)->handle($team, 'Shipped', 'Hi {customer_name}, shipped!');

    expect($template->team_id)->toBe($team->id);
    expect($template->name)->toBe('Shipped');
    expect(ReplyTemplate::query()->count())->toBe(1);
});

test('updating a reply template changes its name and body', function () {
    $template = ReplyTemplate::factory()->create(['name' => 'Old', 'body_with_variables' => 'Old body']);

    $updated = app(UpdateReplyTemplateAction::class)->handle($template, 'New', 'New body');

    expect($updated->name)->toBe('New');
    expect($updated->body_with_variables)->toBe('New body');
});

test('deleting a reply template removes it', function () {
    $template = ReplyTemplate::factory()->create();

    app(DeleteReplyTemplateAction::class)->handle($template);

    expect(ReplyTemplate::query()->find($template->id))->toBeNull();
});
