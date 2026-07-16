<?php

namespace App\Actions\Admin;

use App\Models\Notification;
use App\Models\SmsLedger;
use App\Models\StoreConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Plan §8.7.7 operations/health board. Every figure here is a live snapshot
 * of real data — no synthetic history charts, since nothing logs
 * webhook-failure or poller-lag *history* yet (only current state). Two
 * items from the spec are honestly omitted entirely: API quota usage for
 * Etsy/Amazon (those adapters are still stubs — nothing to meter) and
 * trial-abuse fingerprint matches (no fingerprinting mechanism exists
 * anywhere in the codebase yet, despite being flagged as a risk in §13).
 */
class GetOpsHealthSnapshotAction
{
    private const STALE_SYNC_HOURS = 2;

    private const RUNAWAY_RULE_THRESHOLD = 50;

    /**
     * @return array<string, mixed>
     */
    public function handle(): array
    {
        return [
            'connections' => $this->connectionHealth(),
            'failed_jobs' => $this->failedJobs(),
            'sms' => $this->smsHealth(),
            'notification_volume_24h' => $this->notificationVolume(),
            'abuse' => $this->abuseFlags(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function connectionHealth(): array
    {
        $connections = StoreConnection::query()->get(['id', 'status', 'last_sync_at']);
        $staleCutoff = now()->subHours(self::STALE_SYNC_HOURS);

        return [
            'total' => $connections->count(),
            'needs_reauth' => $connections->where('status', StoreConnection::STATUS_NEEDS_REAUTH)->count(),
            'disconnected' => $connections->where('status', StoreConnection::STATUS_DISCONNECTED)->count(),
            'stale' => $connections
                ->where('status', StoreConnection::STATUS_ACTIVE)
                ->filter(fn (StoreConnection $c) => $c->last_sync_at !== null && $c->last_sync_at->lt($staleCutoff))
                ->count(),
            'never_synced' => $connections
                ->where('status', StoreConnection::STATUS_ACTIVE)
                ->filter(fn (StoreConnection $c) => $c->last_sync_at === null)
                ->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function failedJobs(): array
    {
        $recent = DB::table('failed_jobs')
            ->orderByDesc('failed_at')
            ->limit(20)
            ->get(['uuid', 'queue', 'failed_at', 'exception'])
            ->map(fn ($row) => [
                'uuid' => $row->uuid,
                'queue' => $row->queue,
                'failed_at' => $row->failed_at,
                'exception_summary' => Str::limit(explode("\n", (string) $row->exception)[0], 140),
            ]);

        return [
            'total' => DB::table('failed_jobs')->count(),
            'recent' => $recent->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function smsHealth(): array
    {
        $sinceMonthStart = now()->startOfMonth();

        $topConsumers = DB::table('sms_ledger')
            ->join('teams', 'teams.id', '=', 'sms_ledger.team_id')
            ->where('sms_ledger.reason', SmsLedger::REASON_SEND)
            ->where('sms_ledger.created_at', '>=', $sinceMonthStart)
            ->selectRaw('sms_ledger.team_id, teams.name as team_name, SUM(-sms_ledger.delta) as consumed')
            ->groupBy('sms_ledger.team_id', 'teams.name')
            ->orderByDesc('consumed')
            ->limit(5)
            ->get();

        return [
            'consumed_this_month' => (int) $topConsumers->sum('consumed'),
            'top_consumers' => $topConsumers->map(fn ($row) => [
                'team_id' => (int) $row->team_id,
                'team_name' => (string) $row->team_name,
                'consumed' => (int) $row->consumed,
            ])->all(),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function notificationVolume(): array
    {
        return Notification::query()
            ->where('created_at', '>=', now()->subDay())
            ->selectRaw('type, count(*) as count')
            ->groupBy('type')
            ->pluck('count', 'type')
            ->map(fn ($count) => (int) $count)
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function abuseFlags(): array
    {
        $since = now()->subHour();

        $runaway = DB::table('rule_executions')
            ->join('rules', 'rules.id', '=', 'rule_executions.rule_id')
            ->join('teams', 'teams.id', '=', 'rules.team_id')
            ->where('rule_executions.fired_at', '>=', $since)
            ->selectRaw('rules.team_id, teams.name as team_name, count(*) as executions')
            ->groupBy('rules.team_id', 'teams.name')
            ->having('executions', '>', self::RUNAWAY_RULE_THRESHOLD)
            ->orderByDesc('executions')
            ->get();

        return [
            'runaway_rule_teams' => $runaway->map(fn ($row) => [
                'team_id' => (int) $row->team_id,
                'team_name' => (string) $row->team_name,
                'executions_last_hour' => (int) $row->executions,
            ])->all(),
            'threshold_per_hour' => self::RUNAWAY_RULE_THRESHOLD,
        ];
    }
}
