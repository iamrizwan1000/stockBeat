<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\Support\CreateCannedReplyAction;
use App\Actions\Admin\Support\DeleteCannedReplyAction;
use App\Actions\Admin\Support\UpdateCannedReplyAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveCannedReplyRequest;
use App\Models\AdminUser;
use App\Models\CannedReply;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CannedReplyController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/canned-replies/index', [
            'replies' => CannedReply::query()->orderBy('title')->get(['id', 'title', 'body']),
        ]);
    }

    public function store(SaveCannedReplyRequest $request, CreateCannedReplyAction $action): RedirectResponse
    {
        $action->handle($this->admin($request), $request->string('title')->toString(), $request->string('body')->toString());

        return back()->with('status', 'Canned reply created.');
    }

    public function update(SaveCannedReplyRequest $request, CannedReply $cannedReply, UpdateCannedReplyAction $action): RedirectResponse
    {
        $action->handle($this->admin($request), $cannedReply, $request->string('title')->toString(), $request->string('body')->toString());

        return back()->with('status', 'Canned reply updated.');
    }

    public function destroy(Request $request, CannedReply $cannedReply, DeleteCannedReplyAction $action): RedirectResponse
    {
        $action->handle($this->admin($request), $cannedReply);

        return back()->with('status', 'Canned reply deleted.');
    }

    private function admin(Request $request): AdminUser
    {
        /** @var AdminUser $admin */
        $admin = $request->user('admin');

        return $admin;
    }
}
