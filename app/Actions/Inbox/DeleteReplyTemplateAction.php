<?php

namespace App\Actions\Inbox;

use App\Models\ReplyTemplate;

class DeleteReplyTemplateAction
{
    public function handle(ReplyTemplate $template): void
    {
        $template->delete();
    }
}
