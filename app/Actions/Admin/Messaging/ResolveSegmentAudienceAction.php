<?php

namespace App\Actions\Admin\Messaging;

use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Builds the `User` query matching a segment's filters (Plan §8.7.5), reused
 * by the segment preview count, broadcast audience resolution at send time,
 * and single-user announcement matching. `filters` shape:
 * {plan?: string, platform?: string, inactive_days_gte?: int,
 * trial_ending_within_days?: int, marketing_opt_in?: bool}. `plan` uses the
 * same values as `ListCustomersAction` — a `Subscription::STATUS_*` or the
 * `'free'` sentinel for "no subscription" — not `Plan::key`, since a team's
 * subscription status is what's actually queryable. `country` isn't
 * supported: no country field is tracked on `users` yet (same gap as
 * `ListCustomersAction`).
 */
class ResolveSegmentAudienceAction
{
    /**
     * @param  array<string, mixed>|null  $filters
     * @return Builder<User>
     */
    public function handle(?array $filters): Builder
    {
        $query = User::query()->whereNull('suspended_at');

        $this->applyFilters($query, $filters ?? []);

        return $query;
    }

    /**
     * @param  Builder<User>  $query
     * @param  array<string, mixed>  $filters
     */
    public function applyFilters(Builder $query, array $filters): void
    {
        if (! empty($filters['platform'])) {
            $platform = $filters['platform'];
            $query->whereHas('ownedTeam.storeConnections', fn ($q) => $q->where('platform', $platform));
        }

        if (! empty($filters['plan'])) {
            $plan = $filters['plan'];

            if ($plan === 'free') {
                $query->whereDoesntHave('ownedTeam.subscription');
            } else {
                $query->whereHas('ownedTeam.subscription', fn ($q) => $q->where('status', $plan));
            }
        }

        if (! empty($filters['inactive_days_gte'])) {
            $cutoff = now()->subDays((int) $filters['inactive_days_gte']);
            $query->where(function ($q) use ($cutoff) {
                $q->whereNull('last_active_at')->orWhere('last_active_at', '<=', $cutoff);
            });
        }

        if (! empty($filters['trial_ending_within_days'])) {
            $withinDays = (int) $filters['trial_ending_within_days'];
            $query->whereHas('ownedTeam.subscription', function ($q) use ($withinDays) {
                $q->where('status', Subscription::STATUS_TRIAL)
                    ->whereNotNull('trial_ends_at')
                    ->whereBetween('trial_ends_at', [now(), now()->addDays($withinDays)]);
            });
        }

        if (array_key_exists('marketing_opt_in', $filters) && $filters['marketing_opt_in'] !== null) {
            $query->where('marketing_opt_in', (bool) $filters['marketing_opt_in']);
        }
    }
}
