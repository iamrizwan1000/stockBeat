<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Inbox\CreateReplyTemplateAction;
use App\Actions\Inbox\DeleteReplyTemplateAction;
use App\Actions\Inbox\UpdateReplyTemplateAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inbox\SaveReplyTemplateRequest;
use App\Http\Resources\ReplyTemplateResource;
use App\Http\Responses\ApiResponse;
use App\Models\ReplyTemplate;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Inbox
 */
class ReplyTemplateController extends Controller
{
    /**
     * List saved reply templates.
     *
     * @response 200 scenario="success" {
     *   "success": true,
     *   "message": null,
     *   "data": {
     *     "templates": [
     *       { "id": 1, "name": "Shipped", "body_with_variables": "Hi {customer_name}, order {order_number} shipped! Tracking: {tracking}" }
     *     ]
     *   }
     * }
     */
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $team = $user->currentTeam();

        $templates = $team === null
            ? collect()
            : ReplyTemplate::query()->where('team_id', $team->id)->orderBy('name')->get();

        return ApiResponse::success(['templates' => ReplyTemplateResource::collection($templates)]);
    }

    /**
     * Create a reply template.
     *
     * @response 201 scenario="success" {
     *   "success": true,
     *   "message": null,
     *   "data": {
     *     "template": { "id": 2, "name": "Delay", "body_with_variables": "Hi {customer_name}, order {order_number} is running a little late." }
     *   }
     * }
     */
    public function store(SaveReplyTemplateRequest $request, CreateReplyTemplateAction $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $team = $user->currentTeam();

        if ($team === null) {
            return ApiResponse::error('Complete profile setup before creating reply templates.', status: 422);
        }

        $template = $action->handle($team, $request->string('name')->toString(), $request->string('body_with_variables')->toString());

        return ApiResponse::success(['template' => new ReplyTemplateResource($template)], status: 201);
    }

    public function update(SaveReplyTemplateRequest $request, ReplyTemplate $replyTemplate, UpdateReplyTemplateAction $action): JsonResponse
    {
        $this->authorizeTemplateAccess($request, $replyTemplate);

        $template = $action->handle($replyTemplate, $request->string('name')->toString(), $request->string('body_with_variables')->toString());

        return ApiResponse::success(['template' => new ReplyTemplateResource($template)]);
    }

    public function destroy(Request $request, ReplyTemplate $replyTemplate, DeleteReplyTemplateAction $action): JsonResponse
    {
        $this->authorizeTemplateAccess($request, $replyTemplate);

        $action->handle($replyTemplate);

        return ApiResponse::success(message: 'Reply template deleted.');
    }

    private function authorizeTemplateAccess(Request $request, ReplyTemplate $template): void
    {
        /** @var User $user */
        $user = $request->user();

        if ($template->team_id !== $user->currentTeam()?->id) {
            abort(404);
        }
    }
}
