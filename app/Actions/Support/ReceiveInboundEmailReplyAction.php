<?php

namespace App\Actions\Support;

use App\Models\SupportMessage;
use App\Models\SupportThread;

/**
 * A user's email reply threading back into their support conversation
 * (Plan §4.9: "the user can answer either in-app or by replying to the
 * email"). The `from` address must match the thread's own user — otherwise
 * anyone who learns a `support+{id}@` address could inject messages into a
 * stranger's thread. A mismatch is silently dropped (returns `null`), not
 * an error — the inbound email endpoint's own boundary is a shared secret
 * (Plan §17.7 pattern), so a mismatch here is closer to "wrong reply-to"
 * than "attack," but it must never write into the wrong thread either way.
 */
class ReceiveInboundEmailReplyAction
{
    public function __construct(
        private readonly SendUserSupportMessageAction $sendUserMessage,
    ) {}

    public function handle(SupportThread $thread, string $fromEmail, string $body): ?SupportMessage
    {
        if (strcasecmp($thread->user->email, $fromEmail) !== 0) {
            return null;
        }

        return $this->sendUserMessage->handle($thread->user, $body);
    }
}
