<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\UpdatePlanLimitAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdatePlanLimitRequest;
use App\Models\AdminUser;
use App\Models\Plan;
use App\Models\PlanLimit;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class PlanController extends Controller
{
    public function index(): Response
    {
        $plans = Plan::query()
            ->with('limits')
            ->get()
            ->map(fn (Plan $plan) => [
                'id' => $plan->id,
                'key' => $plan->key,
                'name' => $plan->name,
                'active' => $plan->active,
                'limits' => $plan->limits->map(fn (PlanLimit $limit) => [
                    'id' => $limit->id,
                    'key' => $limit->key,
                    'value' => $limit->value,
                ])->all(),
            ]);

        return Inertia::render('admin/plans/index', ['plans' => $plans]);
    }

    public function update(UpdatePlanLimitRequest $request, PlanLimit $limit, UpdatePlanLimitAction $action): RedirectResponse
    {
        /** @var AdminUser $admin */
        $admin = $request->user('admin');

        $action->handle($admin, $limit, $request->input('value'));

        return back()->with('status', 'Plan limit updated.');
    }
}
