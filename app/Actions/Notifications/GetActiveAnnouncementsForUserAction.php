<?php

namespace App\Actions\Notifications;

use App\Actions\Admin\Messaging\ResolveSegmentAudienceAction;
use App\Models\Announcement;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Active, audience-matched in-app banners for the mobile app's `GET
 * /api/v1/announcements` (Plan §10's `GET /config` announcements slice —
 * `min_version`/remote flags aren't built, so this is scoped to just
 * announcements rather than a partial `/config`). Reuses
 * `ResolveSegmentAudienceAction`'s filter logic against a single user so an
 * announcement's audience rules are evaluated identically to how a
 * broadcast/segment would match them.
 */
class GetActiveAnnouncementsForUserAction
{
    public function __construct(
        private readonly ResolveSegmentAudienceAction $resolveAudience,
    ) {}

    /**
     * @return Collection<int, Announcement>
     */
    public function handle(User $user): Collection
    {
        $now = now();

        return Announcement::query()
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now))
            ->whereDoesntHave('dismissals', fn ($q) => $q->where('user_id', $user->id))
            ->latest()
            ->get()
            ->filter(fn (Announcement $announcement) => $this->matchesAudience($user, $announcement->audience));
    }

    /**
     * @param  array<string, mixed>|null  $audience
     */
    private function matchesAudience(User $user, ?array $audience): bool
    {
        if ($audience === null || $audience === []) {
            return true;
        }

        $query = User::query()->where('id', $user->id);
        $this->resolveAudience->applyFilters($query, $audience);

        return $query->exists();
    }
}
