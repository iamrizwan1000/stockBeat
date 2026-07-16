<?php

namespace App\Console\Commands;

use App\Models\Team;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Hard-deletes users and teams whose 30-day GDPR grace period (Plan §4.8
 * "account deletion") has elapsed. Force-deleting cascades through the
 * existing `cascadeOnDelete` foreign keys (team_members, store_connections,
 * orders, rules, subscriptions, etc.) — nothing extra to clean up here.
 */
class PurgeDeletedAccounts extends Command
{
    private const GRACE_PERIOD_DAYS = 30;

    protected $signature = 'accounts:purge-deleted';

    protected $description = 'Hard-delete users and teams past their GDPR deletion grace period';

    public function handle(): int
    {
        $cutoff = now()->subDays(self::GRACE_PERIOD_DAYS);

        $users = User::onlyTrashed()->where('deleted_at', '<=', $cutoff)->get();
        $teams = Team::onlyTrashed()->where('deleted_at', '<=', $cutoff)->get();

        foreach ($users as $user) {
            $user->forceDelete();
        }

        foreach ($teams as $team) {
            $team->forceDelete();
        }

        $this->info("Purged {$users->count()} user(s) and {$teams->count()} team(s).");

        return self::SUCCESS;
    }
}
