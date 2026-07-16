<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\Messaging\CreateAnnouncementAction;
use App\Actions\Admin\Messaging\DeleteAnnouncementAction;
use App\Actions\Admin\Messaging\UpdateAnnouncementAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveAnnouncementRequest;
use App\Models\AdminUser;
use App\Models\Announcement;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AnnouncementController extends Controller
{
    public function index(): Response
    {
        $announcements = Announcement::query()
            ->latest()
            ->get()
            ->map(fn (Announcement $announcement) => [
                'id' => $announcement->id,
                'title' => $announcement->title,
                'body' => $announcement->body,
                'audience' => $announcement->audience,
                'starts_at' => $announcement->starts_at,
                'ends_at' => $announcement->ends_at,
                'dismissible' => $announcement->dismissible,
                'is_active' => $announcement->isActive(),
            ]);

        return Inertia::render('admin/announcements/index', ['announcements' => $announcements]);
    }

    public function store(SaveAnnouncementRequest $request, CreateAnnouncementAction $action): RedirectResponse
    {
        $action->handle($this->admin($request), $request->validated());

        return back()->with('status', 'Announcement created.');
    }

    public function update(SaveAnnouncementRequest $request, Announcement $announcement, UpdateAnnouncementAction $action): RedirectResponse
    {
        $action->handle($this->admin($request), $announcement, $request->validated());

        return back()->with('status', 'Announcement updated.');
    }

    public function destroy(Request $request, Announcement $announcement, DeleteAnnouncementAction $action): RedirectResponse
    {
        $action->handle($this->admin($request), $announcement);

        return back()->with('status', 'Announcement deleted.');
    }

    private function admin(Request $request): AdminUser
    {
        /** @var AdminUser $admin */
        $admin = $request->user('admin');

        return $admin;
    }
}
