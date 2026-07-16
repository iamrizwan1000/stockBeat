<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\GetOpsHealthSnapshotAction;
use App\Actions\Admin\UpdateAppConfigAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateAppConfigRequest;
use App\Models\AdminUser;
use App\Models\AppConfig;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class OpsController extends Controller
{
    public function index(GetOpsHealthSnapshotAction $action): Response
    {
        $config = AppConfig::query()->get()->mapWithKeys(fn (AppConfig $c) => [$c->key => $c->value]);

        return Inertia::render('admin/ops/index', [
            'health' => $action->handle(),
            'config' => [
                'min_version' => $config[AppConfig::KEY_MIN_VERSION] ?? null,
                'maintenance_mode' => $config[AppConfig::KEY_MAINTENANCE_MODE] ?? false,
                'maintenance_banner' => $config[AppConfig::KEY_MAINTENANCE_BANNER] ?? null,
            ],
        ]);
    }

    public function updateConfig(UpdateAppConfigRequest $request, UpdateAppConfigAction $action): RedirectResponse
    {
        /** @var AdminUser $admin */
        $admin = $request->user('admin');

        $action->handle($admin, $request->string('key')->toString(), $request->input('value'));

        return back()->with('status', 'App config updated.');
    }
}
