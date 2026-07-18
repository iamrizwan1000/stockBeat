<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\Messaging\ApproveBroadcastAction;
use App\Actions\Admin\Messaging\CreateBroadcastAction;
use App\Actions\Admin\Messaging\SendBroadcastAction;
use App\Actions\Admin\Messaging\SendTestBroadcastAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveBroadcastRequest;
use App\Models\AdminUser;
use App\Models\Broadcast;
use App\Models\BroadcastDelivery;
use App\Models\Segment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BroadcastController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->only(['q', 'status']);

        $broadcasts = Broadcast::query()
            ->with(['segment', 'user', 'createdBy'])
            ->when($filters['q'] ?? null, fn ($query, $q) => $query->where('title', 'like', "%{$q}%"))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->latest()
            ->get()
            ->map(fn (Broadcast $broadcast) => $this->summarize($broadcast));

        return Inertia::render('admin/broadcasts/index', ['broadcasts' => $broadcasts, 'filters' => $filters]);
    }

    public function create(): Response
    {
        $segments = Segment::query()->orderBy('name')->get(['id', 'name']);

        return Inertia::render('admin/broadcasts/create', ['segments' => $segments]);
    }

    public function store(SaveBroadcastRequest $request, CreateBroadcastAction $action): RedirectResponse
    {
        $broadcast = $action->handle($this->admin($request), $request->validated());

        return redirect()->route('admin.broadcasts.show', $broadcast)->with('status', 'Broadcast saved as draft.');
    }

    public function show(Broadcast $broadcast): Response
    {
        $broadcast->load(['segment', 'user', 'createdBy', 'approvedBy']);

        $deliveryCounts = $broadcast->deliveries()
            ->selectRaw('channel, status, count(*) as count')
            ->groupBy('channel', 'status')
            ->get()
            ->groupBy('channel')
            ->map(fn ($rows) => $rows->pluck('count', 'status'));

        // "62% opened" (Plan §8.7.5): opened/read rate over every delivery
        // that actually went out (`status = sent`) — the honest denominator,
        // since a skipped/failed delivery was never eligible to be opened.
        $sentCount = (int) $broadcast->deliveries()->where('status', BroadcastDelivery::STATUS_SENT)->count();
        $openedCount = (int) $broadcast->deliveries()->where('status', BroadcastDelivery::STATUS_SENT)->whereNotNull('opened_at')->count();

        return Inertia::render('admin/broadcasts/show', [
            'broadcast' => [
                ...$this->summarize($broadcast),
                'body' => $broadcast->body,
                'template_vars_available' => ['{first_name}', '{plan}', '{trial_days_left}'],
            ],
            'delivery_counts' => $deliveryCounts,
            'open_stats' => [
                'sent' => $sentCount,
                'opened' => $openedCount,
                'rate' => $sentCount > 0 ? (int) round(($openedCount / $sentCount) * 100) : null,
            ],
        ]);
    }

    public function sendTest(Request $request, Broadcast $broadcast, SendTestBroadcastAction $action): RedirectResponse
    {
        $action->handle($this->admin($request), $broadcast);

        return back()->with('status', 'Test sent to your own email.');
    }

    public function approve(Request $request, Broadcast $broadcast, ApproveBroadcastAction $action): RedirectResponse
    {
        $action->handle($this->admin($request), $broadcast);

        return back()->with('status', 'Broadcast approved for sending to all users.');
    }

    public function send(Request $request, Broadcast $broadcast, SendBroadcastAction $action): RedirectResponse
    {
        $action->handle($this->admin($request), $broadcast);

        return back()->with('status', 'Broadcast sent.');
    }

    /**
     * @return array<string, mixed>
     */
    private function summarize(Broadcast $broadcast): array
    {
        return [
            'id' => $broadcast->id,
            'audience_type' => $broadcast->audience_type,
            'segment_name' => $broadcast->segment?->name,
            'recipient_email' => $broadcast->user?->email,
            'channels' => $broadcast->channels,
            'title' => $broadcast->title,
            'status' => $broadcast->status,
            'scheduled_at' => $broadcast->scheduled_at,
            'sent_at' => $broadcast->sent_at,
            'stats' => $broadcast->stats,
            'created_by_name' => $broadcast->createdBy?->name,
            'approved_by_name' => $broadcast->approvedBy?->name,
            'approved_at' => $broadcast->approved_at,
            'created_at' => $broadcast->created_at,
        ];
    }

    private function admin(Request $request): AdminUser
    {
        /** @var AdminUser $admin */
        $admin = $request->user('admin');

        return $admin;
    }
}
