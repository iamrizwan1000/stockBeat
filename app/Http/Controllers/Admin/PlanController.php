<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\UpdatePlanLimitAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdatePlanLimitRequest;
use App\Models\AdminUser;
use App\Models\ContentBlock;
use App\Models\Plan;
use App\Models\PlanLimit;
use App\Models\SmsTopupPack;
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

        $smsTopupPacks = SmsTopupPack::query()
            ->orderBy('sort_order')
            ->orderBy('key')
            ->get()
            ->map(fn (SmsTopupPack $pack) => [
                'id' => $pack->id,
                'key' => $pack->key,
                'name' => $pack->name,
                'sms_credits' => $pack->sms_credits,
                'price_usd' => (string) $pack->price_usd,
                'active' => $pack->active,
                'sort_order' => $pack->sort_order,
            ]);

        $contentBlocks = ContentBlock::query()
            ->orderBy('key')
            ->get()
            ->map(fn (ContentBlock $block) => [
                'id' => $block->id,
                'key' => $block->key,
                'title' => $block->title,
                'body' => $block->body,
                'locale' => $block->locale,
                'active' => $block->active,
            ]);

        return Inertia::render('admin/plans/index', [
            'plans' => $plans,
            'smsTopupPacks' => $smsTopupPacks,
            'contentBlocks' => $contentBlocks,
        ]);
    }

    public function update(UpdatePlanLimitRequest $request, PlanLimit $limit, UpdatePlanLimitAction $action): RedirectResponse
    {
        /** @var AdminUser $admin */
        $admin = $request->user('admin');

        $action->handle($admin, $limit, $request->input('value'));

        return back()->with('status', 'Plan limit updated.');
    }
}
