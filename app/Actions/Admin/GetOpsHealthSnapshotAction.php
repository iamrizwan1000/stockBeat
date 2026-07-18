<?php

namespace App\Actions\Admin;

use App\Models\Notification;
use App\Models\OpsHealthSnapshot;
use App\Models\SmsLedger;
use App\Models\StoreConnection;
use App\Support\Connections\ApiQuotaTracker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Plan §8.7.7 operations/health board. `abuse` covers runaway rule volume,
 * trial-abuse fingerprint matches, and (as of §8.7.2's per-customer flags
 * pass) teams over the flat SMS-cost threshold — the same
 * `DetectAccountAbuseSignalsAction::highSmsCostTeams()` the customer detail
 * page's own "high SMS cost" badge is computed from, so the two views
 * never disagree.
 *
 * A prior audit of this action flagged three gaps against §8.7.7, all
 * closed here:
 *  - `api_quota_usage` — per-platform outbound call counts against each
 *    platform's documented rate limit, via `ApiQuotaTracker` (see its own
 *    docblock for the counting mechanism and hook sites).
 *  - `sms_anomalies` — per-team SMS *anomaly* detection (a team's own
 *    volume spiking relative to its own history), distinct from
 *    `high_sms_cost_teams`' flat absolute threshold below.
 *  - `trending` — a 30-day time series read from `ops_health_snapshots`
 *    (written daily by `ops:record-daily-snapshot` /
 *    `RecordOpsHealthSnapshotAction`), the first historical (not
 *    current-state-only) figures on this board.
 */
class GetOpsHealthSnapshotAction
{
    private const STALE_SYNC_HOURS = 2;

    private const RUNAWAY_RULE_THRESHOLD = 50;

    /**
     * A team's current-24h SMS volume must be at least this many times its
     * own trailing 28-day daily average to count as an anomaly — chosen
     * (per the brief's own ">5x" example) as a level that a normal day's
     * variance in send volume essentially never reaches, while still
     * catching a real runaway (a misfiring rule, a compromised account)
     * within its first day rather than waiting for the *absolute*
     * `high_sms_cost_teams` monthly threshold to eventually trip.
     */
    private const SMS_ANOMALY_MULTIPLE = 5;

    /**
     * Floor below which a "5x baseline" flag is noise rather than signal
     * (e.g. a team whose baseline is 1 credit/day sending 6 credits today
     * is technically "6x" but not an operational concern) — same "arbitrary
     * but honest" category as `RUNAWAY_RULE_THRESHOLD`/
     * `DetectAccountAbuseSignalsAction::HIGH_SMS_COST_THRESHOLD`.
     */
    private const SMS_ANOMALY_MIN_CURRENT = 20;

    /**
     * Trailing window a team's current SMS volume is baselined against —
     * 4 weeks (28 days), long enough to smooth over a single unusually
     * busy/quiet day or a weekly cadence (e.g. a Monday digest-triggered
     * rule) without being so long it drags in ancient, no-longer-relevant
     * history.
     */
    private const SMS_BASELINE_DAYS = 28;

    /**
     * Etsy: 10,000 requests/day per app (Plan §7.4). eBay: ~5,000/day *per
     * API* by default (Plan §7.3) — Fulfillment, Inventory, and Trading
     * each get their own allowance, but `ApiQuotaTracker` aggregates every
     * eBay call under one counter (see its own docblock), so this is
     * compared against a single conservative 5,000 figure rather than
     * three separate ones. Amazon and TikTok have no clean daily-limit
     * number — see `apiQuotaUsage()` below for how each is handled
     * honestly instead of faked.
     */
    private const ETSY_DAILY_LIMIT = 10_000;

    private const EBAY_DAILY_LIMIT = 5_000;

    /**
     * Amazon's getOrders token bucket is ~0.0167 requests/sec, burst 20
     * (Plan §7.5) — a sustained-rate limit, not a daily quota. This is the
     * theoretical daily ceiling *if* that sustained rate ran nonstop for
     * 24h (0.0167 * 86,400 ≈ 1,443), used only as an approximate
     * yardstick — `apiQuotaUsage()` labels it explicit as an
     * approximation rather than presenting it as a real documented daily
     * cap the way Etsy's/eBay's figures are.
     */
    private const AMAZON_APPROX_DAILY_CEILING = 1_443;

    public function __construct(
        private readonly DetectTrialAbuseFlagsAction $detectTrialAbuse,
        private readonly DetectAccountAbuseSignalsAction $detectAccountAbuse,
    ) {}

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
            'api_quota_usage' => $this->apiQuotaUsage(),
            'sms_anomalies' => $this->smsAnomalies(),
            'trending' => $this->trending(),
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

        $trialAbuse = $this->detectTrialAbuse->handle();

        return [
            'runaway_rule_teams' => $runaway->map(fn ($row) => [
                'team_id' => (int) $row->team_id,
                'team_name' => (string) $row->team_name,
                'executions_last_hour' => (int) $row->executions,
            ])->all(),
            'threshold_per_hour' => self::RUNAWAY_RULE_THRESHOLD,
            'shared_fingerprint_teams' => $trialAbuse['shared_fingerprint_teams'],
            'shared_signup_ip_teams' => $trialAbuse['shared_signup_ip_teams'],
            'high_sms_cost_teams' => $this->detectAccountAbuse->highSmsCostTeams(),
            'high_sms_cost_threshold' => DetectAccountAbuseSignalsAction::HIGH_SMS_COST_THRESHOLD,
        ];
    }

    /**
     * Per-platform outbound call counts (today) against each platform's
     * documented rate limit — closes §8.7.7 gap #1. See `ApiQuotaTracker`
     * for the counting mechanism and exactly which call sites increment
     * it, and the class-level daily-limit constants above for where each
     * number comes from.
     *
     * @return array<string, array<string, mixed>>
     */
    private function apiQuotaUsage(): array
    {
        $etsyCalls = ApiQuotaTracker::callsToday(StoreConnection::PLATFORM_ETSY);
        $ebayCalls = ApiQuotaTracker::callsToday(StoreConnection::PLATFORM_EBAY);
        $amazonCalls = ApiQuotaTracker::callsToday(StoreConnection::PLATFORM_AMAZON);
        $tiktokCalls = ApiQuotaTracker::callsToday(StoreConnection::PLATFORM_TIKTOK);

        return [
            'etsy' => [
                'calls_today' => $etsyCalls,
                'daily_limit' => self::ETSY_DAILY_LIMIT,
                'pct_used' => round($etsyCalls / self::ETSY_DAILY_LIMIT * 100, 1),
                'note' => '10,000 requests/day per app (Plan §7.4) — an exact, documented figure.',
            ],
            'ebay' => [
                'calls_today' => $ebayCalls,
                'daily_limit' => self::EBAY_DAILY_LIMIT,
                'pct_used' => round($ebayCalls / self::EBAY_DAILY_LIMIT * 100, 1),
                'note' => 'Default ~5,000/day per API (Plan §7.3); Fulfillment/Inventory/Trading calls are all '
                    .'aggregated into one counter here and compared against a single conservative 5,000 figure '
                    .'rather than three separate per-API budgets.',
            ],
            'amazon' => [
                'calls_today' => $amazonCalls,
                'daily_limit' => self::AMAZON_APPROX_DAILY_CEILING,
                'pct_used' => round($amazonCalls / self::AMAZON_APPROX_DAILY_CEILING * 100, 1),
                'note' => 'Amazon has no daily quota — getOrders is a strict per-endpoint token bucket '
                    .'(~0.0167 req/s, burst 20, Plan §7.5). The figure here is an approximation only: the '
                    .'theoretical ceiling if that sustained rate ran for a full 24h, not a real documented cap — '
                    .'treat "% used" as a rough sustained-rate comparison, not a precise quota reading.',
            ],
            'tiktok' => [
                'calls_today' => $tiktokCalls,
                'daily_limit' => null,
                'pct_used' => null,
                'note' => 'TikTok Shop Partner API rate limits aren\'t documented anywhere in Plan §7.6 — calls '
                    .'are still counted for visibility, but with no known budget to compare against.',
            ],
        ];
    }

    /**
     * Flags teams whose SMS volume in the last 24h is an abnormal multiple
     * of *their own* trailing history — closes §8.7.7 gap #2. Distinct
     * from `high_sms_cost_teams` above (a flat month-to-date absolute
     * threshold, same number for every team): this instead asks "is this
     * team behaving very differently from how it normally behaves",
     * catching a runaway on day one for a team whose normal volume is
     * small enough that it would never cross the absolute threshold at
     * all.
     *
     * Rule (exact, so it's auditable): current = SMS credits sent in the
     * last 24h; baseline = that same team's average daily SMS credits sent
     * over the trailing 28 days *before* that 24h window. Flagged when
     * `baseline > 0` (a team needs real history to have a "normal" to
     * deviate from — a brand-new team's first-ever send is not an
     * anomaly, it's a first data point; genuinely abusive first-day volume
     * is still caught by the flat `high_sms_cost_teams` threshold) AND
     * `current >= max(SMS_ANOMALY_MIN_CURRENT, SMS_ANOMALY_MULTIPLE *
     * baseline)`.
     *
     * @return array<int, array{team_id: int, team_name: string, current: int, baseline: float, multiple: float}>
     */
    private function smsAnomalies(): array
    {
        $currentWindowStart = now()->subDay();
        $baselineWindowStart = $currentWindowStart->copy()->subDays(self::SMS_BASELINE_DAYS);

        $current = DB::table('sms_ledger')
            ->join('teams', 'teams.id', '=', 'sms_ledger.team_id')
            ->where('sms_ledger.reason', SmsLedger::REASON_SEND)
            ->where('sms_ledger.created_at', '>=', $currentWindowStart)
            ->selectRaw('sms_ledger.team_id, teams.name as team_name, SUM(-sms_ledger.delta) as consumed')
            ->groupBy('sms_ledger.team_id', 'teams.name')
            ->get()
            ->keyBy('team_id');

        if ($current->isEmpty()) {
            return [];
        }

        $baseline = DB::table('sms_ledger')
            ->where('reason', SmsLedger::REASON_SEND)
            ->whereIn('team_id', $current->keys())
            ->where('created_at', '>=', $baselineWindowStart)
            ->where('created_at', '<', $currentWindowStart)
            ->selectRaw('team_id, SUM(-delta) as consumed')
            ->groupBy('team_id')
            ->pluck('consumed', 'team_id');

        $anomalies = [];

        foreach ($current as $teamId => $row) {
            $currentConsumed = (int) $row->consumed;
            $baselineAvg = ((float) ($baseline[$teamId] ?? 0)) / self::SMS_BASELINE_DAYS;

            if ($baselineAvg <= 0) {
                continue;
            }

            $threshold = max(self::SMS_ANOMALY_MIN_CURRENT, self::SMS_ANOMALY_MULTIPLE * $baselineAvg);

            if ($currentConsumed < $threshold) {
                continue;
            }

            $anomalies[] = [
                'team_id' => (int) $teamId,
                'team_name' => (string) $row->team_name,
                'current' => $currentConsumed,
                'baseline' => round($baselineAvg, 2),
                'multiple' => round($currentConsumed / $baselineAvg, 1),
            ];
        }

        usort($anomalies, fn (array $a, array $b) => $b['multiple'] <=> $a['multiple']);

        return $anomalies;
    }

    /**
     * 30-day time series read from `ops_health_snapshots` — closes §8.7.7
     * gap #3. Written daily by `ops:record-daily-snapshot`
     * (`RecordOpsHealthSnapshotAction`); this method only reads, never
     * writes, so viewing the Ops page never itself creates history.
     *
     * @return array<int, array<string, mixed>>
     */
    private function trending(): array
    {
        return OpsHealthSnapshot::query()
            ->where('date', '>=', now()->subDays(30)->toDateString())
            ->orderBy('date')
            ->get()
            ->map(fn (OpsHealthSnapshot $snapshot) => [
                'date' => $snapshot->date->toDateString(),
                'active_teams' => $snapshot->active_teams,
                'mrr' => $snapshot->mrr,
                'churned_teams' => $snapshot->churned_teams,
                'total_orders_synced' => $snapshot->total_orders_synced,
                'failed_jobs_total' => $snapshot->failed_jobs_total,
                'sms_cost_total' => $snapshot->sms_cost_total,
            ])
            ->all();
    }
}
