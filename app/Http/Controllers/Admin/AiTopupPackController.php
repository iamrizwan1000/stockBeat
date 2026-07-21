<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\AiTopupPacks\CreateAiTopupPackAction;
use App\Actions\Admin\AiTopupPacks\DeleteAiTopupPackAction;
use App\Actions\Admin\AiTopupPacks\UpdateAiTopupPackAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CreateAiTopupPackRequest;
use App\Http\Requests\Admin\UpdateAiTopupPackRequest;
use App\Models\AdminUser;
use App\Models\AiTopupPack;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * AI question top-up pack catalog — the AI-question counterpart to
 * `SmsTopupPackController` (Plan §5/§8.7.3). No dedicated admin page
 * section wires to this yet (deliberately deferred alongside the mobile
 * purchase-sheet UI, both real follow-ups once RevenueCat products exist)
 * — packs can be managed via these endpoints directly in the meantime.
 */
class AiTopupPackController extends Controller
{
    public function store(CreateAiTopupPackRequest $request, CreateAiTopupPackAction $action): RedirectResponse
    {
        $action->handle($this->admin($request), $request->validated());

        return back()->with('status', 'AI top-up pack created.');
    }

    public function update(UpdateAiTopupPackRequest $request, AiTopupPack $aiPack, UpdateAiTopupPackAction $action): RedirectResponse
    {
        $action->handle($this->admin($request), $aiPack, $request->validated());

        return back()->with('status', 'AI top-up pack updated.');
    }

    public function destroy(Request $request, AiTopupPack $aiPack, DeleteAiTopupPackAction $action): RedirectResponse
    {
        $action->handle($this->admin($request), $aiPack);

        return back()->with('status', 'AI top-up pack deleted.');
    }

    private function admin(Request $request): AdminUser
    {
        /** @var AdminUser $admin */
        $admin = $request->user('admin');

        return $admin;
    }
}
