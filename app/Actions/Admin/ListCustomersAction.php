<?php

namespace App\Actions\Admin;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Plan §8.7.2 customer list — search + filters. `country` and `LTV`
 * filters from the spec aren't included: neither is tracked anywhere yet
 * (no billing-country field, no real payment history).
 */
class ListCustomersAction
{
    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, User>
     */
    public function handle(array $filters): LengthAwarePaginator
    {
        $query = User::query()->with(['ownedTeam.subscription', 'ownedTeam.storeConnections']);

        if (! empty($filters['q'])) {
            $search = $filters['q'];
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('business_name', 'like', "%{$search}%");
            });
        }

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

        if (! empty($filters['signup_from'])) {
            $query->where('created_at', '>=', $filters['signup_from']);
        }

        if (! empty($filters['signup_to'])) {
            $query->where('created_at', '<=', $filters['signup_to']);
        }

        if (! empty($filters['last_active_from'])) {
            $query->where('last_active_at', '>=', $filters['last_active_from']);
        }

        return $query->orderByDesc('created_at')->paginate(25)->withQueryString();
    }
}
