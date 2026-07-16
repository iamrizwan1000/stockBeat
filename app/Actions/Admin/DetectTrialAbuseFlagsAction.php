<?php

namespace App\Actions\Admin;

use App\Models\StoreConnection;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Plan §8.7.7 "trial-abuse fingerprint matches." Two independent signals:
 *
 * - The same underlying store (by `store_connections.fingerprint`, Plan
 *   §8.7.7/`ComputeStoreConnectionFingerprintAction`) connected under more
 *   than one team — the same shop farming multiple free trials.
 * - The same signup IP (`users.signup_ip`) behind more than one team that
 *   has actually consumed a trial (has a subscription row) — one person
 *   spinning up throwaway accounts from one network.
 *
 * Both are honest best-effort signals, not proof: a shared office/VPN IP or
 * a legitimate re-connect of the same store after an account handoff both
 * look identical here. Flagged for admin review, never auto-actioned.
 */
class DetectTrialAbuseFlagsAction
{
    /**
     * @return array<string, mixed>
     */
    public function handle(): array
    {
        return [
            'shared_fingerprint_teams' => $this->sharedFingerprintTeams(),
            'shared_signup_ip_teams' => $this->sharedSignupIpTeams(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function sharedFingerprintTeams(): array
    {
        $fingerprints = DB::table('store_connections')
            ->whereNotNull('fingerprint')
            ->select('fingerprint')
            ->groupBy('fingerprint')
            ->havingRaw('COUNT(DISTINCT team_id) > 1')
            ->pluck('fingerprint');

        if ($fingerprints->isEmpty()) {
            return [];
        }

        $connections = StoreConnection::query()
            ->whereIn('fingerprint', $fingerprints)
            ->with('team')
            ->get(['id', 'team_id', 'platform', 'fingerprint']);

        return $connections->groupBy('fingerprint')
            ->map(fn ($group) => [
                'fingerprint' => $group->first()->fingerprint,
                'platform' => $group->first()->platform,
                'teams' => $group->unique('team_id')->values()->map(fn (StoreConnection $c) => [
                    'team_id' => $c->team_id,
                    'team_name' => $c->team?->name,
                ])->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function sharedSignupIpTeams(): array
    {
        $ips = DB::table('users')
            ->whereNotNull('signup_ip')
            ->select('signup_ip')
            ->groupBy('signup_ip')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('signup_ip');

        if ($ips->isEmpty()) {
            return [];
        }

        $users = User::query()
            ->whereIn('signup_ip', $ips)
            ->whereHas('ownedTeam.subscription')
            ->with('ownedTeam')
            ->get(['id', 'name', 'email', 'signup_ip']);

        return $users->groupBy('signup_ip')
            ->filter(fn ($group) => $group->count() > 1)
            ->map(fn ($group, $ip) => [
                'signup_ip' => $ip,
                'teams' => $group->map(fn (User $user) => [
                    'team_id' => $user->ownedTeam?->id,
                    'team_name' => $user->ownedTeam?->name,
                    'user_email' => $user->email,
                ])->all(),
            ])
            ->values()
            ->all();
    }
}
