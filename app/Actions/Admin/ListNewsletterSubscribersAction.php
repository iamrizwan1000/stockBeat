<?php

namespace App\Actions\Admin;

use App\Models\NewsletterSubscriber;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ListNewsletterSubscribersAction
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function handle(array $filters): LengthAwarePaginator
    {
        return $this->query($filters)
            ->orderByDesc('subscribed_at')
            ->paginate(25)
            ->withQueryString();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<NewsletterSubscriber>
     */
    private function query(array $filters): Builder
    {
        $q = trim((string) ($filters['q'] ?? ''));
        $status = $filters['status'] ?? null;

        return NewsletterSubscriber::query()
            ->when($q !== '', fn ($query) => $query->where('email', 'like', "%{$q}%"))
            ->when($status === 'subscribed', fn ($query) => $query->whereNull('unsubscribed_at'))
            ->when($status === 'unsubscribed', fn ($query) => $query->whereNotNull('unsubscribed_at'));
    }

    /**
     * The full filtered set, unpaginated — for CSV export, which must
     * include every match, not just the current page the admin happens to
     * be viewing.
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<int, NewsletterSubscriber>
     */
    public function all(array $filters): Collection
    {
        return $this->query($filters)->orderByDesc('subscribed_at')->get();
    }
}
