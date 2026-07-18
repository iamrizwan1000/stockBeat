<?php

namespace App\Actions\Admin\Promotions;

use App\Actions\Admin\Messaging\ResolveSegmentAudienceAction;
use App\Actions\Billing\ConvertToBaseCurrencyAction;
use App\Models\PromoCampaign;
use App\Models\PromoCampaignRedemption;
use App\Models\Segment;
use App\Models\SubscriptionEvent;
use Illuminate\Support\Collection;

/**
 * Turns a `server_comp` `PromoCampaign`'s redemptions into real numbers for
 * the campaign show page (Plan §8.7.4). Computed on demand rather than
 * cached into `promo_campaigns.stats` — same choice `GetCustomerDetailAction`
 * makes for `ComputeCustomerLtvAction` — because admin traffic here is a
 * handful of page loads, not a hot path, and live numbers are always
 * correct instead of "correct as of the last scheduled run."
 *
 * `offer_code`/`intro_offer` campaigns are configured store-side (Apple/
 * Google consoles) and have no RevenueCat payload field tying an event back
 * to a specific offer code, so their stats aren't computable here — see
 * `PromoCampaign`'s own docblock. `computable: false` signals that to the
 * UI instead of silently showing zeroes.
 */
class ComputeCampaignStatsAction
{
    /**
     * Every revenue figure here is converted into this currency so amounts
     * across teams with different `base_currency` settings can be summed
     * into one number — same sentinel `VerifyOtpAction` assigns every new
     * user before they set up their profile, so it's the natural default
     * rather than an arbitrary pick.
     */
    private const REPORTING_CURRENCY = 'USD';

    public function __construct(
        private readonly ResolveSegmentAudienceAction $resolveAudience,
        private readonly ConvertToBaseCurrencyAction $convertToBaseCurrency,
    ) {}

    /**
     * @return array{
     *     computable: bool,
     *     reason: string|null,
     *     redemptions: int|null,
     *     targeted_segment_size: int|null,
     *     conversion: float|null,
     *     revenue_impact: float|null,
     *     revenue_impact_currency: string|null,
     *     revenue_events_included: int|null,
     *     revenue_events_excluded_no_price: int|null,
     *     revenue_events_excluded_no_fx_rate: int|null,
     * }
     */
    public function handle(PromoCampaign $campaign): array
    {
        if ($campaign->type !== PromoCampaign::TYPE_SERVER_COMP) {
            return [
                'computable' => false,
                'reason' => 'Offer-code and introductory-offer campaigns are configured in the Apple/Google consoles — no RevenueCat payload field ties an event back to a specific campaign, so redemption/conversion/revenue stats can\'t be computed here.',
                'redemptions' => null,
                'targeted_segment_size' => null,
                'conversion' => null,
                'revenue_impact' => null,
                'revenue_impact_currency' => null,
                'revenue_events_included' => null,
                'revenue_events_excluded_no_price' => null,
                'revenue_events_excluded_no_fx_rate' => null,
            ];
        }

        $redemptions = PromoCampaignRedemption::query()
            ->where('promo_campaign_id', $campaign->id)
            ->get(['team_id', 'redeemed_at']);

        $targetedSize = $this->targetedSegmentSize($campaign);
        $conversion = ($targetedSize !== null && $targetedSize > 0)
            ? round($redemptions->count() / $targetedSize, 4)
            : null;

        $revenue = $this->revenueImpact($redemptions);

        return [
            'computable' => true,
            'reason' => null,
            'redemptions' => $redemptions->count(),
            'targeted_segment_size' => $targetedSize,
            'conversion' => $conversion,
            'revenue_impact' => $revenue['total'],
            'revenue_impact_currency' => self::REPORTING_CURRENCY,
            'revenue_events_included' => $revenue['included'],
            'revenue_events_excluded_no_price' => $revenue['excluded_no_price'],
            'revenue_events_excluded_no_fx_rate' => $revenue['excluded_no_fx_rate'],
        ];
    }

    /**
     * Denominator for `conversion` — the current size of whatever
     * segment(s) this campaign was actually applied to (from the
     * `stats.applications` log `ApplyServerCompToSegmentAction` already
     * writes), reusing `ResolveSegmentAudienceAction` exactly like the
     * segment preview-count endpoint does. `null` if the campaign has never
     * been applied yet (nothing to divide by). A `null` `segment_id` in the
     * log means "applied to everyone," which takes precedence since every
     * other targeted team is already included in that audience.
     *
     * Note this is the segment's *current* membership, not a snapshot of who
     * matched at the moment the campaign was applied — segment membership
     * shifts over time (churn, new signups), so like `ComputeCustomerLtvAction`'s
     * FX gaps, this is an honest best-effort figure, not a frozen historical one.
     */
    private function targetedSegmentSize(PromoCampaign $campaign): ?int
    {
        /** @var array<int, array<string, mixed>> $applications */
        $applications = $campaign->stats['applications'] ?? [];

        if ($applications === []) {
            return null;
        }

        $segmentIds = collect($applications)->pluck('segment_id');

        if ($segmentIds->contains(null)) {
            return $this->teamIdsForFilters(null)->count();
        }

        /** @var Collection<int, int> $teamIds */
        $teamIds = collect();

        foreach ($segmentIds->filter()->unique() as $segmentId) {
            $segment = Segment::query()->find((int) $segmentId);
            $teamIds = $teamIds->merge($this->teamIdsForFilters($segment?->filters));
        }

        return $teamIds->unique()->count();
    }

    /**
     * @param  array<string, mixed>|null  $filters
     * @return Collection<int, int>
     */
    private function teamIdsForFilters(?array $filters): Collection
    {
        return $this->resolveAudience->handle($filters)
            ->with('ownedTeam')
            ->get()
            ->pluck('ownedTeam.id')
            ->filter()
            ->unique()
            ->values();
    }

    /**
     * Sums `subscription_events.price` (converted to `REPORTING_CURRENCY`)
     * for every event on a redeeming team that happened on or after that
     * team's `redeemed_at` — "revenue after the comp landed," not the
     * team's whole lifetime revenue. One query for every candidate event
     * rather than one query per redemption, then windowed in PHP per team.
     *
     * @param  Collection<int, PromoCampaignRedemption>  $redemptions
     * @return array{total: float, included: int, excluded_no_price: int, excluded_no_fx_rate: int}
     */
    private function revenueImpact(Collection $redemptions): array
    {
        if ($redemptions->isEmpty()) {
            return ['total' => 0.0, 'included' => 0, 'excluded_no_price' => 0, 'excluded_no_fx_rate' => 0];
        }

        $teamIds = $redemptions->pluck('team_id')->unique();
        $earliestRedeemedAt = $redemptions->min('redeemed_at');

        $eventsByTeam = SubscriptionEvent::query()
            ->whereIn('team_id', $teamIds)
            ->where('occurred_at', '>=', $earliestRedeemedAt->toDateTimeString())
            ->get(['team_id', 'price', 'currency', 'occurred_at'])
            ->groupBy('team_id');

        $total = 0.0;
        $included = 0;
        $excludedNoPrice = 0;
        $excludedNoFxRate = 0;

        foreach ($redemptions as $redemption) {
            $events = $eventsByTeam->get($redemption->team_id, collect());

            foreach ($events as $event) {
                if ($event->occurred_at->lt($redemption->redeemed_at)) {
                    continue;
                }

                if ($event->price === null || $event->currency === null) {
                    $excludedNoPrice++;

                    continue;
                }

                $converted = $this->convertToBaseCurrency->handle(
                    $event->price,
                    $event->currency,
                    self::REPORTING_CURRENCY,
                    $event->occurred_at,
                );

                if ($converted === null) {
                    $excludedNoFxRate++;

                    continue;
                }

                $total += $converted;
                $included++;
            }
        }

        return [
            'total' => round($total, 2),
            'included' => $included,
            'excluded_no_price' => $excludedNoPrice,
            'excluded_no_fx_rate' => $excludedNoFxRate,
        ];
    }
}
