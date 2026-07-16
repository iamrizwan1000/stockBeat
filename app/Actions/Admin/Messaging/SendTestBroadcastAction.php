<?php

namespace App\Actions\Admin\Messaging;

use App\Mail\BroadcastTestMail;
use App\Models\AdminUser;
use App\Models\Broadcast;
use Illuminate\Support\Facades\Mail;

/**
 * "Preview + send-test-to-self" (Plan §8.7.5). Sent to the requesting
 * admin's own email, not a real recipient, so template variables are
 * rendered with fixed placeholder values rather than a real user's data —
 * admins have no team/subscription to interpolate against.
 */
class SendTestBroadcastAction
{
    private const PLACEHOLDER_VARS = [
        '{first_name}' => 'Jamie',
        '{plan}' => 'Pro',
        '{trial_days_left}' => '3',
    ];

    public function handle(AdminUser $admin, Broadcast $broadcast): void
    {
        $title = strtr($broadcast->title, self::PLACEHOLDER_VARS);
        $body = strtr($broadcast->body, self::PLACEHOLDER_VARS);

        Mail::to($admin->email)->queue(new BroadcastTestMail($title, $body));
    }
}
