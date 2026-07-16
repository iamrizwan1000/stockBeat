<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\Promotions\ApplyServerCompToSegmentAction;
use App\Actions\Admin\Promotions\CreatePromoCampaignAction;
use App\Actions\Admin\Promotions\DeletePromoCampaignAction;
use App\Actions\Admin\Promotions\UpdatePromoCampaignAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ApplyServerCompRequest;
use App\Http\Requests\Admin\SavePromoCampaignRequest;
use App\Models\AdminUser;
use App\Models\PromoCampaign;
use App\Models\Segment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PromoCampaignController extends Controller
{
    public function index(): Response
    {
        $campaigns = PromoCampaign::query()
            ->with('createdBy')
            ->latest()
            ->get()
            ->map(fn (PromoCampaign $campaign) => $this->summarize($campaign));

        $segments = Segment::query()->orderBy('name')->get(['id', 'name']);

        return Inertia::render('admin/promotions/index', [
            'campaigns' => $campaigns,
            'segments' => $segments,
        ]);
    }

    public function store(SavePromoCampaignRequest $request, CreatePromoCampaignAction $action): RedirectResponse
    {
        $action->handle($this->admin($request), $request->validated());

        return back()->with('status', 'Campaign created.');
    }

    public function update(SavePromoCampaignRequest $request, PromoCampaign $promoCampaign, UpdatePromoCampaignAction $action): RedirectResponse
    {
        $action->handle($this->admin($request), $promoCampaign, $request->validated());

        return back()->with('status', 'Campaign updated.');
    }

    public function destroy(Request $request, PromoCampaign $promoCampaign, DeletePromoCampaignAction $action): RedirectResponse
    {
        $action->handle($this->admin($request), $promoCampaign);

        return back()->with('status', 'Campaign deleted.');
    }

    public function applyServerComp(ApplyServerCompRequest $request, PromoCampaign $promoCampaign, ApplyServerCompToSegmentAction $action): RedirectResponse
    {
        $action->handle($this->admin($request), $promoCampaign, $request->integer('segment_id') ?: null);

        return back()->with('status', 'Comp applied.');
    }

    /**
     * @return array<string, mixed>
     */
    private function summarize(PromoCampaign $campaign): array
    {
        return [
            'id' => $campaign->id,
            'name' => $campaign->name,
            'type' => $campaign->type,
            'store_ref' => $campaign->store_ref,
            'config' => $campaign->config,
            'starts_at' => $campaign->starts_at,
            'ends_at' => $campaign->ends_at,
            'is_active' => $campaign->isActive(),
            'stats' => $campaign->stats,
            'created_by_name' => $campaign->createdBy?->name,
            'created_at' => $campaign->created_at,
        ];
    }

    private function admin(Request $request): AdminUser
    {
        /** @var AdminUser $admin */
        $admin = $request->user('admin');

        return $admin;
    }
}
