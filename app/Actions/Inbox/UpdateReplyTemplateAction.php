<?php

namespace App\Actions\Inbox;

use App\Models\ReplyTemplate;

class UpdateReplyTemplateAction
{
    public function handle(ReplyTemplate $template, string $name, string $bodyWithVariables): ReplyTemplate
    {
        $template->update(['name' => $name, 'body_with_variables' => $bodyWithVariables]);

        return $template;
    }
}
