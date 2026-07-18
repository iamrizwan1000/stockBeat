<?php

namespace App\Actions\Admin;

use App\Models\SmsLedger;
use App\Models\Team;
use Illuminate\Support\Facades\DB;

/**
 * Plan §8.7.2 per-customer "flags (trial-abuse suspect, high SMS cost)" —
 * shares the exact same heuristics already surfaced in aggregate on the Ops
 * & Health board (§8.7.7/`GetOpsHealthSnapshotAction`) rather than
 * reimplementing them, so a given team gets the same answer whichever screen
 * asks. Trial-abuse detection is delegated straight to the existing
 * `DetectTrialAbuseFlagsAction` (fingerprint + signup-IP signals); this class
 * only adds the "does *this* team appear in that flagged set" lookup, plus
 * the "high SMS cost this month" signal (previously only a top-5 ranking on
 * Ops & Health, not a flag) which both this action and
 * `GetOpsHealthSnapshotAction` now call `highSmsCostTeams()` for.
 */
class DetectAccountAbuseSignalsAction
{
    /**
     * SMS credits consumed in the current calendar month above which a team
     * is flagged "high SMS cost" — an arbitrary threshold, same honest-guess
     * category as `GetOpsHealthSnapshotAction::RUNAWAY_RULE_THRESHOLD`, not
     * derived from any real cost-per-credit figure.
     */
    public const HIGH_SMS_COST_THRESHOLD = 200;

    public function __construct(
        private readonly DetectTrialAbuseFlagsAction $detectTrialAbuse,
    ) {}

    /**
     * @return array{trial_abuse_suspected: bool, high_sms_cost: bool}
     */
    public function handle(Team $team): array
    {
        return [
            'trial_abuse_suspected' => $this->isSuspectedOfTrialAbuse($team),
            'high_sms_cost' => in_array(
                $team->id,
                array_column($this->highSmsCostTeams(), 'team_id'),
                true,
            ),
        ];
    }

    private function isSuspectedOfTrialAbuse(Team $team): bool
    {
        $flags = $this->detectTrialAbuse->handle();

        /** @var array<int, array<string, mixed>> $fingerprintGroups */
        $fingerprintGroups = $flags['shared_fingerprint_teams'];
        /** @var array<int, array<string, mixed>> $signupIpGroups */
        $signupIpGroups = $flags['shared_signup_ip_teams'];

        $inFingerprintGroup = collect($fingerprintGroups)
            ->contains(function (array $group) use ($team) {
                /** @var array<int, array<string, mixed>> $teams */
                $teams = $group['teams'];

                return collect($teams)->contains('team_id', $team->id);
            });

        $inSignupIpGroup = collect($signupIpGroups)
            ->contains(function (array $group) use ($team) {
                /** @var array<int, array<string, mixed>> $teams */
                $teams = $group['teams'];

                return collect($teams)->contains('team_id', $team->id);
            });

        return $inFingerprintGroup || $inSignupIpGroup;
    }

    /**
     * Every team whose SMS spend this calendar month is over the threshold
     * — the same query the Ops & Health board's abuse block surfaces, reused
     * rather than duplicated so the per-customer flag and the aggregate list
     * can never disagree.
     *
     * @return array<int, array{team_id: int, team_name: string, consumed: int}>
     */
    public function highSmsCostTeams(): array
    {
        return DB::table('sms_ledger')
            ->join('teams', 'teams.id', '=', 'sms_ledger.team_id')
            ->where('sms_ledger.reason', SmsLedger::REASON_SEND)
            ->where('sms_ledger.created_at', '>=', now()->startOfMonth())
            ->selectRaw('sms_ledger.team_id, teams.name as team_name, SUM(-sms_ledger.delta) as consumed')
            ->groupBy('sms_ledger.team_id', 'teams.name')
            ->havingRaw('SUM(-sms_ledger.delta) > ?', [self::HIGH_SMS_COST_THRESHOLD])
            ->orderByDesc('consumed')
            ->get()
            ->map(fn ($row) => [
                'team_id' => (int) $row->team_id,
                'team_name' => (string) $row->team_name,
                'consumed' => (int) $row->consumed,
            ])
            ->all();
    }
}
