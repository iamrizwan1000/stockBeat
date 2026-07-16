<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Rules\CreateRuleAction;
use App\Actions\Rules\TestFireRuleAction;
use App\Actions\Rules\UpdateRuleAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Rules\StoreRuleRequest;
use App\Http\Requests\Rules\UpdateRuleRequest;
use App\Http\Resources\RuleExecutionResource;
use App\Http\Resources\RuleResource;
use App\Http\Responses\ApiResponse;
use App\Models\Rule;
use App\Models\RuleExecution;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RuleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $team = $user->currentTeam();

        $rules = $team === null
            ? collect()
            : Rule::query()->where('team_id', $team->id)->latest()->get();

        return ApiResponse::success(['rules' => RuleResource::collection($rules)]);
    }

    public function store(StoreRuleRequest $request, CreateRuleAction $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $team = $user->currentTeam();

        if ($team === null) {
            return ApiResponse::error('Complete profile setup before creating rules.', status: 422);
        }

        $rule = $action->handle($team, $user, $request->validated());

        return ApiResponse::success(['rule' => new RuleResource($rule)], status: 201);
    }

    public function update(UpdateRuleRequest $request, Rule $rule, UpdateRuleAction $action): JsonResponse
    {
        $this->authorizeRuleAccess($request, $rule);

        $rule = $action->handle($rule, $request->validated());

        return ApiResponse::success(['rule' => new RuleResource($rule)]);
    }

    public function test(Request $request, Rule $rule, TestFireRuleAction $action): JsonResponse
    {
        $this->authorizeRuleAccess($request, $rule);

        $execution = $action->handle($rule);

        return ApiResponse::success(['execution' => new RuleExecutionResource($execution)]);
    }

    public function executions(Request $request, Rule $rule): JsonResponse
    {
        $this->authorizeRuleAccess($request, $rule);

        $executions = RuleExecution::query()
            ->where('rule_id', $rule->id)
            ->orderByDesc('fired_at')
            ->limit(50)
            ->get();

        return ApiResponse::success(['executions' => RuleExecutionResource::collection($executions)]);
    }

    private function authorizeRuleAccess(Request $request, Rule $rule): void
    {
        /** @var User $user */
        $user = $request->user();

        if ($rule->team_id !== $user->currentTeam()?->id) {
            abort(404);
        }
    }
}
