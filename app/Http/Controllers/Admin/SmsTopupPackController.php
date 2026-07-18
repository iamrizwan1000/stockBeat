<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\SmsTopupPacks\CreateSmsTopupPackAction;
use App\Actions\Admin\SmsTopupPacks\DeleteSmsTopupPackAction;
use App\Actions\Admin\SmsTopupPacks\UpdateSmsTopupPackAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CreateSmsTopupPackRequest;
use App\Http\Requests\Admin\UpdateSmsTopupPackRequest;
use App\Models\AdminUser;
use App\Models\SmsTopupPack;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Plan §5/§8.7.3 — SMS credit top-up pack catalog, edited from the
 * "SMS packs" tab of the Plans & Pricing admin page (`admin/plans/index`).
 */
class SmsTopupPackController extends Controller
{
    public function store(CreateSmsTopupPackRequest $request, CreateSmsTopupPackAction $action): RedirectResponse
    {
        $action->handle($this->admin($request), $request->validated());

        return back()->with('status', 'SMS top-up pack created.');
    }

    public function update(UpdateSmsTopupPackRequest $request, SmsTopupPack $smsPack, UpdateSmsTopupPackAction $action): RedirectResponse
    {
        $action->handle($this->admin($request), $smsPack, $request->validated());

        return back()->with('status', 'SMS top-up pack updated.');
    }

    public function destroy(Request $request, SmsTopupPack $smsPack, DeleteSmsTopupPackAction $action): RedirectResponse
    {
        $action->handle($this->admin($request), $smsPack);

        return back()->with('status', 'SMS top-up pack deleted.');
    }

    private function admin(Request $request): AdminUser
    {
        /** @var AdminUser $admin */
        $admin = $request->user('admin');

        return $admin;
    }
}
