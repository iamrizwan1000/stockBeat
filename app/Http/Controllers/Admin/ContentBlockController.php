<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\ContentBlocks\CreateContentBlockAction;
use App\Actions\Admin\ContentBlocks\DeleteContentBlockAction;
use App\Actions\Admin\ContentBlocks\UpdateContentBlockAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CreateContentBlockRequest;
use App\Http\Requests\Admin\UpdateContentBlockRequest;
use App\Models\AdminUser;
use App\Models\ContentBlock;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Plan §5.1/§8.7.3 — paywall & store-listing copy blocks, edited from the
 * "Content blocks" tab of the Plans & Pricing admin page (`admin/plans/index`).
 */
class ContentBlockController extends Controller
{
    public function store(CreateContentBlockRequest $request, CreateContentBlockAction $action): RedirectResponse
    {
        $action->handle($this->admin($request), $request->validated());

        return back()->with('status', 'Content block created.');
    }

    public function update(UpdateContentBlockRequest $request, ContentBlock $contentBlock, UpdateContentBlockAction $action): RedirectResponse
    {
        $action->handle($this->admin($request), $contentBlock, $request->validated());

        return back()->with('status', 'Content block updated.');
    }

    public function destroy(Request $request, ContentBlock $contentBlock, DeleteContentBlockAction $action): RedirectResponse
    {
        $action->handle($this->admin($request), $contentBlock);

        return back()->with('status', 'Content block deleted.');
    }

    private function admin(Request $request): AdminUser
    {
        /** @var AdminUser $admin */
        $admin = $request->user('admin');

        return $admin;
    }
}
