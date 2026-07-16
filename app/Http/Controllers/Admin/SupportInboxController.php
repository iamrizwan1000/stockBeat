<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\GetCustomerDetailAction;
use App\Actions\Admin\Support\AddSupportNoteAction;
use App\Actions\Admin\Support\AssignThreadAction;
use App\Actions\Admin\Support\ResolveThreadAction;
use App\Actions\Admin\Support\SendStaffReplyAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SendSupportReplyRequest;
use App\Models\AdminUser;
use App\Models\CannedReply;
use App\Models\SupportThread;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SupportInboxController extends Controller
{
    public function index(Request $request): Response
    {
        $status = $request->string('status')->toString() ?: null;

        $threads = SupportThread::query()
            ->with(['user', 'assignedAdmin'])
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($request->boolean('unassigned'), fn ($q) => $q->whereNull('assigned_admin_id'))
            ->orderByDesc('last_message_at')
            ->get()
            ->map(fn (SupportThread $thread) => [
                'id' => $thread->id,
                'user_name' => $thread->user->name,
                'user_email' => $thread->user->email,
                'status' => $thread->status,
                'priority' => $thread->priority,
                'assigned_admin_name' => $thread->assignedAdmin?->name,
                'last_message_at' => $thread->last_message_at,
            ]);

        return Inertia::render('admin/support/index', [
            'threads' => $threads,
            'filters' => ['status' => $status, 'unassigned' => $request->boolean('unassigned')],
        ]);
    }

    public function show(SupportThread $thread, GetCustomerDetailAction $customerDetail): Response
    {
        $thread->load(['user', 'assignedAdmin', 'messages' => fn ($q) => $q->orderBy('created_at')->with('admin')]);

        return Inertia::render('admin/support/show', [
            'thread' => [
                'id' => $thread->id,
                'status' => $thread->status,
                'priority' => $thread->priority,
                'assigned_admin_id' => $thread->assigned_admin_id,
                'assigned_admin_name' => $thread->assignedAdmin?->name,
            ],
            'messages' => $thread->messages->map(fn ($message) => [
                'id' => $message->id,
                'direction' => $message->direction,
                'admin_name' => $message->admin?->name,
                'body' => $message->body,
                'delivered_via' => $message->delivered_via,
                'created_at' => $message->created_at,
            ]),
            'customer' => $customerDetail->handle($thread->user),
            'canned_replies' => CannedReply::query()->orderBy('title')->get(['id', 'title', 'body']),
        ]);
    }

    public function reply(SendSupportReplyRequest $request, SupportThread $thread, SendStaffReplyAction $action): RedirectResponse
    {
        $action->handle($this->admin($request), $thread, $request->string('body')->toString());

        return back()->with('status', 'Reply sent.');
    }

    public function addNote(SendSupportReplyRequest $request, SupportThread $thread, AddSupportNoteAction $action): RedirectResponse
    {
        $action->handle($this->admin($request), $thread, $request->string('body')->toString());

        return back()->with('status', 'Note added.');
    }

    public function assign(Request $request, SupportThread $thread, AssignThreadAction $action): RedirectResponse
    {
        $assignee = $request->filled('assigned_admin_id') ? AdminUser::query()->find((int) $request->input('assigned_admin_id')) : null;

        $action->handle($this->admin($request), $thread, $assignee);

        return back()->with('status', 'Thread assignment updated.');
    }

    public function resolve(Request $request, SupportThread $thread, ResolveThreadAction $action): RedirectResponse
    {
        $action->handle($this->admin($request), $thread);

        return back()->with('status', 'Thread resolved.');
    }

    private function admin(Request $request): AdminUser
    {
        /** @var AdminUser $admin */
        $admin = $request->user('admin');

        return $admin;
    }
}
