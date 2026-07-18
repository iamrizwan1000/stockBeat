<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Inbox\AssignInboxThreadAction;
use App\Actions\Inbox\RenderReplyTemplateAction;
use App\Actions\Inbox\SendInboxMessageAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inbox\SendInboxMessageRequest;
use App\Http\Resources\InboxMessageResource;
use App\Http\Resources\InboxThreadResource;
use App\Http\Responses\ApiResponse;
use App\Models\InboxThread;
use App\Models\ReplyTemplate;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Inbox
 *
 * Unified customer inbox (Plan §4.5). `sendMessage` (in `SendInboxMessageAction`)
 * routes per-thread by channel: Shopify/Woo via order-linked email, eBay via
 * real Trading API member messages, Etsy the same way but gated behind its
 * own conversations-API approval, Amazon out of scope until that adapter's
 * built.
 */
class ThreadController extends Controller
{
    /**
     * List threads.
     *
     * @queryParam assigned_to integer Filter to threads assigned to this user id. Example: 3
     *
     * @response 200 scenario="success" {
     *   "success": true,
     *   "message": null,
     *   "data": {
     *     "threads": [
     *       { "id": 1, "channel": "woo", "customer_name": "Alex Chen", "customer_email": "alex@example.com", "order_id": 1, "order_number": "#1042", "assigned_to": null, "last_message_at": "2026-07-17T01:00:00.000000Z" }
     *     ]
     *   }
     * }
     */
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $team = $user->currentTeam();

        if ($team === null) {
            return ApiResponse::success(['threads' => []]);
        }

        $threads = InboxThread::query()
            ->where('team_id', $team->id)
            ->with('order')
            ->when($request->filled('assigned_to'), fn ($q) => $q->where('assigned_to', $request->integer('assigned_to')))
            ->orderByDesc('last_message_at')
            ->get();

        return ApiResponse::success(['threads' => InboxThreadResource::collection($threads)]);
    }

    /**
     * List a thread's messages.
     *
     * @response 200 scenario="success" {
     *   "success": true,
     *   "message": null,
     *   "data": {
     *     "messages": [
     *       { "id": 1, "direction": "in", "body": "Where's my order?", "status": "delivered", "created_at": "2026-07-17T01:00:00.000000Z" }
     *     ]
     *   }
     * }
     */
    public function messages(Request $request, InboxThread $thread): JsonResponse
    {
        $this->authorizeThreadAccess($request, $thread);

        $messages = $thread->messages()->orderBy('created_at')->get();

        return ApiResponse::success(['messages' => InboxMessageResource::collection($messages)]);
    }

    /**
     * Send a message.
     *
     * Pass either `body` (free text) or `reply_template_id` (a saved template, rendered
     * server-side with the thread's `{customer_name}`/`{order_number}`/`{tracking}`).
     *
     * @response 201 scenario="success" {
     *   "success": true,
     *   "message": null,
     *   "data": {
     *     "message": { "id": 2, "direction": "out", "body": "It shipped yesterday!", "status": "sent", "created_at": "2026-07-17T01:05:00.000000Z" }
     *   }
     * }
     */
    public function sendMessage(
        SendInboxMessageRequest $request,
        InboxThread $thread,
        SendInboxMessageAction $action,
        RenderReplyTemplateAction $renderTemplate,
    ): JsonResponse {
        $this->authorizeThreadAccess($request, $thread);

        /** @var User $user */
        $user = $request->user();

        $body = $this->resolveMessageBody($request, $thread, $renderTemplate);
        $message = $action->handle($user, $thread, $body);

        return ApiResponse::success(['message' => new InboxMessageResource($message)], status: 201);
    }

    /**
     * Assign (or unassign) a thread to a team member.
     *
     * @response 200 scenario="success" {
     *   "success": true,
     *   "message": null,
     *   "data": {
     *     "thread": { "id": 1, "channel": "woo", "customer_name": "Alex Chen", "customer_email": "alex@example.com", "order_id": 1, "order_number": "#1042", "assigned_to": 3, "last_message_at": "2026-07-17T01:00:00.000000Z" }
     *   }
     * }
     */
    public function assign(Request $request, InboxThread $thread, AssignInboxThreadAction $action): JsonResponse
    {
        $this->authorizeThreadAccess($request, $thread);

        $assignee = $request->filled('user_id') ? User::query()->find($request->integer('user_id')) : null;
        $thread = $action->handle($thread, $assignee);

        return ApiResponse::success(['thread' => new InboxThreadResource($thread)]);
    }

    private function resolveMessageBody(SendInboxMessageRequest $request, InboxThread $thread, RenderReplyTemplateAction $renderTemplate): string
    {
        if (! $request->filled('reply_template_id')) {
            return $request->string('body')->toString();
        }

        $template = ReplyTemplate::query()->findOrFail($request->integer('reply_template_id'));

        return $renderTemplate->handle($template->body_with_variables, $thread);
    }

    private function authorizeThreadAccess(Request $request, InboxThread $thread): void
    {
        /** @var User $user */
        $user = $request->user();

        if ($thread->team_id !== $user->currentTeam()?->id) {
            abort(404);
        }
    }
}
