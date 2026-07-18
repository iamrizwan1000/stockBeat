<?php

namespace App\Actions\Admin\Support;

use App\Models\AdminUser;
use App\Models\SupportMessage;
use App\Models\SupportThread;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Plan §8.7.6 SLA dashboard: first-response time, resolution time,
 * per-agent thread counts, and the CSAT rollup — computed live rather than
 * pre-aggregated, same "live is fine at this scale" convention as
 * `ComputeCustomerLtvAction`/`ComputeCampaignStatsAction` (this is a handful
 * of admin page loads against support-thread volumes that fit comfortably
 * in memory, not a hot path).
 *
 * No new timestamp columns were needed for first-response time — it's
 * derived from `support_messages.direction`/`created_at`, which
 * `SendUserSupportMessageAction`/`SendStaffReplyAction` already stamp on
 * every message. "Thread creation" is used as the fallback start point only
 * when a thread has no user message at all (e.g. a thread seeded directly
 * without messages), since in practice every real thread's `created_at`
 * closely tracks its first user message (`GetOrCreateSupportThreadAction`
 * creates the thread a moment before `SendUserSupportMessageAction` creates
 * that first message in the same request).
 *
 * Resolution time reuses `support_threads.resolved_at`, added by an earlier
 * migration specifically for this dashboard. Threads resolved before that
 * column existed have no historical value to backfill (never captured) and
 * are simply excluded from resolution-time and per-agent resolution
 * averages — same honesty-over-fabrication choice `ComputeCustomerLtvAction`
 * makes for FX gaps.
 *
 * CSAT is stored as a single 👍/👎 (`support_threads.csat`, 1 or 0) captured
 * by `SubmitSupportCsatAction` — there's no separate CSAT table, so the
 * rollup here just aggregates that column rather than inventing a second
 * mechanism.
 *
 * The period filters first-response and CSAT by thread *creation* date
 * (which every thread has) and resolution/per-agent-resolution by
 * *resolution* date (tickets resolved during the period, even if opened
 * earlier) — the two are deliberately different windows, matching how
 * support SLA reporting usually separates "tickets opened this period" from
 * "tickets closed this period". Per-agent `assigned_total` is each agent's
 * *current* full assignment count (a snapshot of today's workload), not
 * scoped to the period.
 */
class ComputeSupportSlaMetricsAction
{
    private const DEFAULT_PERIOD_DAYS = 30;

    /**
     * @return array{
     *     period: array{from: string, to: string},
     *     first_response: array{avg_minutes: float|null, median_minutes: float|null, sample_size: int},
     *     resolution: array{avg_minutes: float|null, median_minutes: float|null, sample_size: int},
     *     agents: array<int, array{admin_id: int, admin_name: string, assigned_total: int, resolved_in_period: int, avg_resolution_minutes: float|null}>,
     *     csat: array{positive: int, negative: int, total: int, positive_pct: float|null},
     * }
     */
    public function handle(?Carbon $from = null, ?Carbon $to = null): array
    {
        $to = ($to ?? now())->copy();
        $from = ($from ?? $to->copy()->subDays(self::DEFAULT_PERIOD_DAYS))->copy();

        $threadsOpenedInPeriod = SupportThread::query()
            ->whereBetween('created_at', [$from, $to])
            ->with(['messages' => fn ($q) => $q->orderBy('created_at')])
            ->get();

        $threadsResolvedInPeriod = SupportThread::query()
            ->whereBetween('resolved_at', [$from, $to])
            ->get(['id', 'assigned_admin_id', 'created_at', 'resolved_at']);

        return [
            'period' => [
                'from' => $from->toIso8601String(),
                'to' => $to->toIso8601String(),
            ],
            'first_response' => $this->summarize($threadsOpenedInPeriod->map(
                fn (SupportThread $thread) => $this->firstResponseMinutes($thread)
            )->all()),
            'resolution' => $this->summarize($threadsResolvedInPeriod->map(
                fn (SupportThread $thread) => $this->resolutionMinutes($thread)
            )->all()),
            'agents' => $this->perAgentStats($threadsResolvedInPeriod),
            'csat' => $this->csatRollup($threadsOpenedInPeriod),
        ];
    }

    /**
     * Minutes from the thread's first user message (falling back to the
     * thread's own `created_at` if it somehow has none) to its first staff
     * reply. `null` if the thread has never had a staff reply yet — such
     * threads are excluded from the average/median rather than counted as
     * an (undefined) infinite wait.
     */
    private function firstResponseMinutes(SupportThread $thread): ?float
    {
        $firstUserMessage = $thread->messages->firstWhere('direction', SupportMessage::DIRECTION_USER);
        $startAt = $thread->created_at;

        if ($firstUserMessage instanceof SupportMessage && $firstUserMessage->created_at !== null) {
            $startAt = $firstUserMessage->created_at;
        }

        if ($startAt === null) {
            return null;
        }

        $firstStaffMessage = $thread->messages
            ->where('direction', SupportMessage::DIRECTION_STAFF)
            ->first(fn (SupportMessage $message) => $message->created_at !== null && $message->created_at->gte($startAt));

        if (! $firstStaffMessage instanceof SupportMessage || $firstStaffMessage->created_at === null) {
            return null;
        }

        return $startAt->diffInSeconds($firstStaffMessage->created_at) / 60;
    }

    /**
     * Minutes from thread creation to `resolved_at`. `null` when the thread
     * has no `resolved_at` (not resolved, or resolved before that column
     * existed).
     */
    private function resolutionMinutes(SupportThread $thread): ?float
    {
        if ($thread->resolved_at === null || $thread->created_at === null) {
            return null;
        }

        return $thread->created_at->diffInSeconds($thread->resolved_at) / 60;
    }

    /**
     * @param  array<int, float|null>  $values
     * @return array{avg_minutes: float|null, median_minutes: float|null, sample_size: int}
     */
    private function summarize(array $values): array
    {
        $present = array_values(array_filter($values, fn (?float $value) => $value !== null));

        $count = count($present);

        if ($count === 0) {
            return ['avg_minutes' => null, 'median_minutes' => null, 'sample_size' => 0];
        }

        sort($present);
        $middle = intdiv($count, 2);

        $median = $count % 2 === 0
            ? ($present[$middle - 1] + $present[$middle]) / 2
            : $present[$middle];

        return [
            'avg_minutes' => round(array_sum($present) / $count, 2),
            'median_minutes' => round($median, 2),
            'sample_size' => $count,
        ];
    }

    /**
     * @param  Collection<int, SupportThread>  $threadsResolvedInPeriod
     * @return array<int, array{admin_id: int, admin_name: string, assigned_total: int, resolved_in_period: int, avg_resolution_minutes: float|null}>
     */
    private function perAgentStats(Collection $threadsResolvedInPeriod): array
    {
        $assignedTotals = SupportThread::query()
            ->whereNotNull('assigned_admin_id')
            ->selectRaw('assigned_admin_id, count(*) as aggregate')
            ->groupBy('assigned_admin_id')
            ->pluck('aggregate', 'assigned_admin_id');

        $resolvedByAgent = $threadsResolvedInPeriod
            ->whereNotNull('assigned_admin_id')
            ->groupBy('assigned_admin_id');

        $adminIds = $assignedTotals->keys()->merge($resolvedByAgent->keys())->unique();

        if ($adminIds->isEmpty()) {
            return [];
        }

        return AdminUser::query()
            ->whereIn('id', $adminIds)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(function (AdminUser $admin) use ($assignedTotals, $resolvedByAgent) {
                /** @var Collection<int, SupportThread> $resolved */
                $resolved = $resolvedByAgent->get($admin->id, collect());

                $resolutionMinutes = $resolved->map(fn (SupportThread $thread) => $this->resolutionMinutes($thread))->all();
                $summary = $this->summarize($resolutionMinutes);

                return [
                    'admin_id' => $admin->id,
                    'admin_name' => $admin->name,
                    'assigned_total' => (int) ($assignedTotals->get($admin->id) ?? 0),
                    'resolved_in_period' => $resolved->count(),
                    'avg_resolution_minutes' => $summary['avg_minutes'],
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, SupportThread>  $threadsOpenedInPeriod
     * @return array{positive: int, negative: int, total: int, positive_pct: float|null}
     */
    private function csatRollup(Collection $threadsOpenedInPeriod): array
    {
        $rated = $threadsOpenedInPeriod->whereNotNull('csat');

        $positive = $rated->where('csat', 1)->count();
        $negative = $rated->where('csat', 0)->count();
        $total = $positive + $negative;

        return [
            'positive' => $positive,
            'negative' => $negative,
            'total' => $total,
            'positive_pct' => $total > 0 ? round($positive / $total * 100, 1) : null,
        ];
    }
}
