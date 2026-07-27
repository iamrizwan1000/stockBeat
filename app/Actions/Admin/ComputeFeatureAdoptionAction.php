<?php

namespace App\Actions\Admin;

use App\Models\FeatureUsageEvent;
use App\Models\OrderEvent;
use App\Models\Payout;
use App\Models\Review;
use App\Models\Rule;
use App\Models\SavedOrderFilter;

/**
 * Real usage counts for the §4.13-§4.23 "identified gap" modules (added
 * 2026-07-27) — before this, there was no way to know whether any of these
 * features were actually being used. `digest_frequency` is filtered in PHP
 * rather than a JSON-path `where()` clause — same "stay portable across
 * MariaDB/SQLite" discipline as goal tracking elsewhere in this codebase,
 * and digest rules are never numerous enough for that to cost anything.
 */
class ComputeFeatureAdoptionAction
{
    /**
     * @return array<string, mixed>
     */
    public function handle(): array
    {
        return [
            'saved_filters' => [
                'count' => SavedOrderFilter::query()->count(),
                'teams' => SavedOrderFilter::query()->distinct('team_id')->count('team_id'),
            ],
            'payouts' => [
                'count' => Payout::query()->count(),
                'teams' => Payout::query()->distinct('team_id')->count('team_id'),
            ],
            'reviews' => $this->reviews(),
            'rules' => $this->ruleAdoption(),
            'quick_actions' => $this->quickActionsViaTimeline(),
            'usage_events' => $this->usageEvents(),
        ];
    }

    /**
     * @return array<string, int|float>
     */
    private function reviews(): array
    {
        $total = Review::query()->count();
        $replied = Review::query()->whereNotNull('replied_at')->count();

        return [
            'total' => $total,
            'replied' => $replied,
            'reply_rate_pct' => $total > 0 ? round($replied / $total * 100, 1) : 0.0,
        ];
    }

    /**
     * @return array<string, int>
     */
    private function ruleAdoption(): array
    {
        $monthlyDigestCount = Rule::query()
            ->where('trigger', Rule::TRIGGER_DIGEST)
            ->get()
            ->filter(fn (Rule $rule) => ($rule->controls['digest_frequency'] ?? 'daily') === 'monthly')
            ->count();

        return [
            'positive_review' => Rule::query()->where('trigger', Rule::TRIGGER_POSITIVE_REVIEW)->count(),
            'stale_inventory' => Rule::query()->where('trigger', Rule::TRIGGER_STALE_INVENTORY)->count(),
            'monthly_digest' => $monthlyDigestCount,
            'priority_critical' => Rule::query()->where('priority', 'critical')->count(),
            'priority_high' => Rule::query()->where('priority', 'high')->count(),
        ];
    }

    /**
     * Every quick action (fulfill/refund/cancel/tag/snooze/note) now writes
     * a real `order_events` row (Plan §4.19) — the first time this was ever
     * measurable at all, single-order or bulk.
     *
     * @return array<string, int>
     */
    private function quickActionsViaTimeline(): array
    {
        return [
            'fulfilled' => OrderEvent::query()->where('type', OrderEvent::TYPE_FULFILLED)->count(),
            'refunded' => OrderEvent::query()->where('type', OrderEvent::TYPE_REFUNDED)->count(),
            'cancelled' => OrderEvent::query()->where('type', OrderEvent::TYPE_CANCELLED)->count(),
            'tags_updated' => OrderEvent::query()->where('type', OrderEvent::TYPE_TAGS_UPDATED)->count(),
            'snoozed' => OrderEvent::query()->where('type', OrderEvent::TYPE_SNOOZED)->count(),
            'note_added' => OrderEvent::query()->where('type', OrderEvent::TYPE_NOTE_ADDED)->count(),
        ];
    }

    /**
     * @return array<string, array{count: int, teams: int}>
     */
    private function usageEvents(): array
    {
        $features = [
            FeatureUsageEvent::FEATURE_INVOICE_GENERATED,
            FeatureUsageEvent::FEATURE_PACKING_SLIP_GENERATED,
            FeatureUsageEvent::FEATURE_BULK_PACKING_SLIPS_GENERATED,
            FeatureUsageEvent::FEATURE_BULK_CANCEL_USED,
            FeatureUsageEvent::FEATURE_BULK_TAG_USED,
        ];

        $result = [];

        foreach ($features as $feature) {
            $result[$feature] = [
                'count' => FeatureUsageEvent::query()->where('feature', $feature)->count(),
                'teams' => FeatureUsageEvent::query()->where('feature', $feature)->distinct('team_id')->count('team_id'),
            ];
        }

        return $result;
    }
}
