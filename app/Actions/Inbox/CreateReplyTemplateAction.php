<?php

namespace App\Actions\Inbox;

use App\Models\ReplyTemplate;
use App\Models\Team;

class CreateReplyTemplateAction
{
    public function handle(Team $team, string $name, string $bodyWithVariables): ReplyTemplate
    {
        return ReplyTemplate::query()->create([
            'team_id' => $team->id,
            'name' => $name,
            'body_with_variables' => $bodyWithVariables,
        ]);
    }
}
