<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Ai\AskAssistantAction;
use App\Actions\Ai\GenerateRuleFromPromptAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ai\AskAssistantRequest;
use App\Http\Requests\Ai\RuleDraftRequest;
use App\Http\Resources\AiConversationResource;
use App\Http\Responses\ApiResponse;
use App\Models\AiConversation;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group AI Assistant
 *
 * Data Copilot + natural-language Rule Builder (Plan §4.12). Every answer
 * is grounded in real tool-call results over the team's own data
 * (`AssistantToolRegistry`) — never a raw guess.
 */
class AssistantController extends Controller
{
    /**
     * Ask the assistant a question.
     *
     * @response 422 scenario="quota exhausted" {
     *   "success": false,
     *   "message": "You've used all 30 AI questions included in your plan this month. Upgrade or wait for next month's reset.",
     *   "errors": { "question": ["You've used all 30 AI questions included in your plan this month. Upgrade or wait for next month's reset."] }
     * }
     */
    public function ask(AskAssistantRequest $request, AskAssistantAction $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $team = $user->currentTeam();

        if ($team === null) {
            return ApiResponse::error('Complete profile setup before using the AI Assistant.', status: 422);
        }

        $conversation = null;

        if ($request->filled('conversation_id')) {
            $conversation = AiConversation::query()->where('team_id', $team->id)->find($request->integer('conversation_id'));

            if ($conversation === null) {
                abort(404);
            }
        }

        $mode = $request->string('mode')->toString() ?: AskAssistantAction::MODE_DATA;
        $conversation = $action->handle($team, $user, $request->string('question')->toString(), $conversation, $mode);
        $conversation->load('messages');

        return ApiResponse::success(['conversation' => new AiConversationResource($conversation)]);
    }

    /**
     * List this team's AI Assistant conversations, most recent first.
     */
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $team = $user->currentTeam();

        $conversations = $team === null
            ? collect()
            : AiConversation::query()->where('team_id', $team->id)->latest()->limit(50)->get();

        return ApiResponse::success(['conversations' => AiConversationResource::collection($conversations)]);
    }

    /**
     * Show one conversation's full message history.
     */
    public function show(Request $request, AiConversation $conversation): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($conversation->team_id !== $user->currentTeam()?->id) {
            abort(404);
        }

        $conversation->load('messages');

        return ApiResponse::success(['conversation' => new AiConversationResource($conversation)]);
    }

    /**
     * Generate a structured rule draft from a plain-English prompt (Pro+).
     * Never persists — the client must POST the returned draft to
     * `POST /rules` after the seller confirms it.
     */
    public function ruleDraft(RuleDraftRequest $request, GenerateRuleFromPromptAction $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $team = $user->currentTeam();

        if ($team === null) {
            return ApiResponse::error('Complete profile setup before using the AI Assistant.', status: 422);
        }

        $result = $action->handle($team, $request->string('prompt')->toString());

        return ApiResponse::success($result);
    }
}
