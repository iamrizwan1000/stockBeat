<?php

namespace App\Actions\Admin;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\LengthAwarePaginator as ManualLengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Request;

/**
 * Plan §8.7.2 customer list — search + filters.
 *
 * `country` (added 2026-07-22): `orders.shipping_address` is encrypted at
 * the column level, so it can't be filtered in SQL at all — that's why
 * `shipping_country` exists as a separate plaintext column populated at
 * ingest (`IngestOrderAction`). This filters "has shipped at least one
 * order to this country," not a single fixed "customer's country" — a
 * seller's customers obviously aren't all in one place.
 *
 * `ltv_min`/`ltv_max` (added 2026-07-22): LTV isn't a stored column — it's
 * computed per-team via `ComputeCustomerLtvAction`, which does per-event
 * FX conversion that isn't expressible as a portable SQL aggregate. When
 * either bound is present, every SQL-filtered candidate is loaded and LTV
 * is computed in PHP, then the range filter and pagination both happen in
 * memory. Fine for an internal admin tool's real scale; would need a
 * cached `teams.ltv_cached` column if the customer base ever got large
 * enough for this to matter.
 */
class ListCustomersAction
{
    public function __construct(
        private readonly ComputeCustomerLtvAction $computeLtv,
    ) {}

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

        if (! empty($filters['country'])) {
            $country = $filters['country'];
            $query->whereHas('ownedTeam.orders', fn ($q) => $q->where('shipping_country', $country));
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

        $query->orderByDesc('created_at');

        $ltvMin = isset($filters['ltv_min']) && $filters['ltv_min'] !== '' ? (float) $filters['ltv_min'] : null;
        $ltvMax = isset($filters['ltv_max']) && $filters['ltv_max'] !== '' ? (float) $filters['ltv_max'] : null;

        if ($ltvMin === null && $ltvMax === null) {
            return $query->paginate(25)->withQueryString();
        }

        return $this->paginateByLtv($query->get(), $ltvMin, $ltvMax);
    }

    /**
     * @param  Collection<int, User>  $candidates
     * @return LengthAwarePaginator<int, User>
     */
    private function paginateByLtv($candidates, ?float $min, ?float $max): LengthAwarePaginator
    {
        $filtered = $candidates->filter(function (User $user) use ($min, $max) {
            if ($user->ownedTeam === null) {
                return false;
            }

            $ltv = $this->computeLtv->handle($user->ownedTeam)['total'];

            return ($min === null || $ltv >= $min) && ($max === null || $ltv <= $max);
        })->values();

        $page = (int) Request::input('page', 1);
        $perPage = 25;

        return new ManualLengthAwarePaginator(
            $filtered->forPage($page, $perPage)->values(),
            $filtered->count(),
            $perPage,
            $page,
            ['path' => Request::url(), 'query' => Request::query()],
        );
    }
}
