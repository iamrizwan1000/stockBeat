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

/**
 * @group Rules
 *
 * Custom notification rules: trigger + condition tree + actions, with quiet hours, cooldown,
 * and an execution log. See Plan §4.4 for the full trigger catalogue (12 triggers, e.g.
 * `new_order`, `unfulfilled_after_x`, `order_spike`, `low_stock`, `digest`).
 */
class RuleController extends Controller
{
    /**
     * List rules.
     *
     * @response 200 scenario="success" {
     *   "success": true,
     *   "message": null,
     *   "data": {
     *     "rules": [
     *       {
     *         "id": 1,
     *         "name": "High-value order alert",
     *         "trigger": "high_value_order",
     *         "conditions": { "all": [{ "field": "total", "operator": ">=", "value": 200 }] },
     *         "actions": [{ "type": "push" }, { "type": "email" }],
     *         "controls": { "quiet_hours": true },
     *         "enabled": true,
     *         "created_at": "2026-07-10T00:00:00.000000Z"
     *       }
     *     ]
     *   }
     * }
     */
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

    /**
     * Create a rule.
     *
     * @response 201 scenario="success" {
     *   "success": true,
     *   "message": null,
     *   "data": {
     *     "rule": {
     *       "id": 2,
     *       "name": "Order spike alert",
     *       "trigger": "order_spike",
     *       "conditions": null,
     *       "actions": [{ "type": "push" }],
     *       "controls": { "spike_count": 10, "spike_window_minutes": 30 },
     *       "enabled": true,
     *       "created_at": "2026-07-16T02:00:00.000000Z"
     *     }
     *   }
     * }
     * @response 422 scenario="profile setup incomplete" {
     *   "success": false,
     *   "message": "Complete profile setup before creating rules.",
     *   "errors": null
     * }
     */
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

    /**
     * Update a rule.
     *
     * @response 200 scenario="success" {
     *   "success": true,
     *   "message": null,
     *   "data": {
     *     "rule": {
     *       "id": 1,
     *       "name": "High-value order alert",
     *       "trigger": "high_value_order",
     *       "conditions": { "all": [{ "field": "total", "operator": ">=", "value": 250 }] },
     *       "actions": [{ "type": "push" }],
     *       "controls": { "quiet_hours": true },
     *       "enabled": true,
     *       "created_at": "2026-07-10T00:00:00.000000Z"
     *     }
     *   }
     * }
     */
    public function update(UpdateRuleRequest $request, Rule $rule, UpdateRuleAction $action): JsonResponse
    {
        $this->authorizeRuleAccess($request, $rule);

        $rule = $action->handle($rule, $request->validated());

        return ApiResponse::success(['rule' => new RuleResource($rule)]);
    }

    /**
     * Test-fire a rule.
     *
     * Evaluates the rule immediately against real state (not a dry run) and logs the execution —
     * lets a merchant confirm a rule works before waiting for it to trigger naturally.
     *
     * @response 200 scenario="success" {
     *   "success": true,
     *   "message": null,
     *   "data": {
     *     "execution": {
     *       "id": 10,
     *       "order_id": null,
     *       "trigger": "high_value_order",
     *       "actions_result": [{ "type": "push", "status": "sent" }],
     *       "fired_at": "2026-07-16T02:00:00.000000Z"
     *     }
     *   }
     * }
     */
    public function test(Request $request, Rule $rule, TestFireRuleAction $action): JsonResponse
    {
        $this->authorizeRuleAccess($request, $rule);

        $execution = $action->handle($rule);

        return ApiResponse::success(['execution' => new RuleExecutionResource($execution)]);
    }

    /**
     * List a rule's execution log.
     *
     * Most recent 50 firings, newest first.
     *
     * @response 200 scenario="success" {
     *   "success": true,
     *   "message": null,
     *   "data": {
     *     "executions": [
     *       {
     *         "id": 10,
     *         "order_id": 1,
     *         "trigger": "high_value_order",
     *         "actions_result": [{ "type": "push", "status": "sent" }],
     *         "fired_at": "2026-07-16T01:00:00.000000Z"
     *       }
     *     ]
     *   }
     * }
     */
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
