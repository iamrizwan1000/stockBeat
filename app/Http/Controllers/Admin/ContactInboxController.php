<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\Contact\SendContactReplyAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SendContactReplyRequest;
use App\Models\AdminUser;
use App\Models\ContactThread;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ContactInboxController extends Controller
{
    public function index(Request $request): Response
    {
        $status = $request->string('status')->toString() ?: null;

        $threads = ContactThread::query()
            ->when($status, fn ($q) => $q->where('status', $status))
            ->orderByDesc('last_message_at')
            ->get()
            ->map(fn (ContactThread $thread) => [
                'id' => $thread->id,
                'name' => $thread->name,
                'email' => $thread->email,
                'subject' => $thread->subject,
                'status' => $thread->status,
                'last_message_at' => $thread->last_message_at,
            ]);

        return Inertia::render('admin/contact-inbox/index', [
            'threads' => $threads,
            'filters' => ['status' => $status],
        ]);
    }

    public function show(ContactThread $thread): Response
    {
        $thread->load(['messages' => fn ($q) => $q->orderBy('created_at')->with('admin')]);

        return Inertia::render('admin/contact-inbox/show', [
            'thread' => [
                'id' => $thread->id,
                'name' => $thread->name,
                'email' => $thread->email,
                'subject' => $thread->subject,
                'status' => $thread->status,
            ],
            'messages' => $thread->messages->map(fn ($message) => [
                'id' => $message->id,
                'direction' => $message->direction,
                'admin_name' => $message->admin?->name,
                'body' => $message->body,
                'created_at' => $message->created_at,
            ]),
        ]);
    }

    public function reply(SendContactReplyRequest $request, ContactThread $thread, SendContactReplyAction $action): RedirectResponse
    {
        $action->handle($this->admin($request), $thread, $request->string('body')->toString());

        return back()->with('status', 'Reply sent.');
    }

    private function admin(Request $request): AdminUser
    {
        /** @var AdminUser $admin */
        $admin = $request->user('admin');

        return $admin;
    }
}
