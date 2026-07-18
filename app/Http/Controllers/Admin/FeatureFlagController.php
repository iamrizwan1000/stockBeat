<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\FeatureFlags\CreateFeatureFlagAction;
use App\Actions\Admin\FeatureFlags\DeleteFeatureFlagAction;
use App\Actions\Admin\FeatureFlags\UpdateFeatureFlagAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CreateFeatureFlagRequest;
use App\Http\Requests\Admin\UpdateFeatureFlagRequest;
use App\Models\AdminUser;
use App\Models\FeatureFlag;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FeatureFlagController extends Controller
{
    public function index(): Response
    {
        $flags = FeatureFlag::query()
            ->orderBy('key')
            ->get()
            ->map(fn (FeatureFlag $flag) => [
                'id' => $flag->id,
                'key' => $flag->key,
                'name' => $flag->name,
                'description' => $flag->description,
                'enabled' => $flag->enabled,
                'rollout_percentage' => $flag->rollout_percentage,
                'enabled_for_team_ids' => $flag->enabled_for_team_ids ?? [],
            ]);

        return Inertia::render('admin/feature-flags/index', ['flags' => $flags]);
    }

    public function store(CreateFeatureFlagRequest $request, CreateFeatureFlagAction $action): RedirectResponse
    {
        $action->handle($this->admin($request), $request->validated());

        return back()->with('status', 'Feature flag created.');
    }

    public function update(UpdateFeatureFlagRequest $request, FeatureFlag $featureFlag, UpdateFeatureFlagAction $action): RedirectResponse
    {
        $action->handle($this->admin($request), $featureFlag, $request->validated());

        return back()->with('status', 'Feature flag updated.');
    }

    public function destroy(Request $request, FeatureFlag $featureFlag, DeleteFeatureFlagAction $action): RedirectResponse
    {
        $action->handle($this->admin($request), $featureFlag);

        return back()->with('status', 'Feature flag deleted.');
    }

    private function admin(Request $request): AdminUser
    {
        /** @var AdminUser $admin */
        $admin = $request->user('admin');

        return $admin;
    }
}
