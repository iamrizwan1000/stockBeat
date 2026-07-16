<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\Messaging\CreateSegmentAction;
use App\Actions\Admin\Messaging\DeleteSegmentAction;
use App\Actions\Admin\Messaging\ResolveSegmentAudienceAction;
use App\Actions\Admin\Messaging\UpdateSegmentAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveSegmentRequest;
use App\Models\AdminUser;
use App\Models\Segment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SegmentController extends Controller
{
    public function index(): Response
    {
        $segments = Segment::query()
            ->withCount('broadcasts')
            ->latest()
            ->get()
            ->map(fn (Segment $segment) => [
                'id' => $segment->id,
                'name' => $segment->name,
                'filters' => $segment->filters,
                'broadcasts_count' => $segment->broadcasts_count,
                'created_at' => $segment->created_at,
            ]);

        return Inertia::render('admin/segments/index', ['segments' => $segments]);
    }

    public function store(SaveSegmentRequest $request, CreateSegmentAction $action): RedirectResponse
    {
        $action->handle($this->admin($request), $request->string('name')->toString(), $request->input('filters'));

        return back()->with('status', 'Segment created.');
    }

    public function update(SaveSegmentRequest $request, Segment $segment, UpdateSegmentAction $action): RedirectResponse
    {
        $action->handle($this->admin($request), $segment, $request->string('name')->toString(), $request->input('filters'));

        return back()->with('status', 'Segment updated.');
    }

    public function destroy(Request $request, Segment $segment, DeleteSegmentAction $action): RedirectResponse
    {
        $action->handle($this->admin($request), $segment);

        return back()->with('status', 'Segment deleted.');
    }

    /**
     * Live audience-size preview while composing a segment or broadcast —
     * a plain JSON endpoint (not an Inertia page) so the compose form can
     * poll it without a full page visit.
     */
    public function previewCount(Request $request, ResolveSegmentAudienceAction $action): JsonResponse
    {
        $count = $action->handle($request->input('filters'))->count();

        return response()->json(['count' => $count]);
    }

    private function admin(Request $request): AdminUser
    {
        /** @var AdminUser $admin */
        $admin = $request->user('admin');

        return $admin;
    }
}
