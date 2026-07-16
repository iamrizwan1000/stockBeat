<?php

namespace App\Actions\Admin;

use App\Models\Device;
use App\Models\Notification;
use App\Models\Rule;
use App\Models\SmsLedger;
use App\Models\StoreConnection;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Plan §8.7.1 dashboard KPIs, computed live from real data. IAP prices are
 * duplicated here from §5 for MRR/ARR estimation only — they live in App
 * Store Connect / Play Console, not a database table, so this must be kept
 * in sync by hand until a real prices table exists. Support-inbox metrics
 * and the "paywall seen" funnel step are omitted — no `support_threads` or
 * paywall-impression tracking exists yet.
 */
class ComputeDashboardKpisAction
{
    /**
     * Revised 2026-07-16 for the 4-tier model (§5) — must stay in sync with
     * `ProcessRevenueCatEventAction::SUBSCRIPTION_PLAN_PRODUCTS` by hand
     * until a real prices table exists.
     */
    private const PRODUCT_PRICES = [
        'starter_monthly' => 5.99,
        'pro_monthly' => 17.99,
        'pro_yearly' => 172.99,
        'premium_monthly' => 44.99,
        'premium_yearly' => 429.99,
    ];

    /**
     * @return array<string, mixed>
     */
    public function handle(): array
    {
        return [
            'signups' => $this->signups(),
            'engagement' => $this->engagement(),
            'trials' => $this->trials(),
            'subscriptions' => $this->subscriptionMetrics(),
            'platforms' => $this->topPlatforms(),
            'notifications' => $this->notificationVolume(),
            'sms' => $this->smsCostVsRevenue(),
            'funnel' => $this->funnel(),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function signups(): array
    {
        return [
            'today' => User::query()->whereDate('created_at', now()->toDateString())->count(),
            'last_7_days' => User::query()->where('created_at', '>=', now()->subDays(7))->count(),
            'last_30_days' => User::query()->where('created_at', '>=', now()->subDays(30))->count(),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function engagement(): array
    {
        return [
            'dau' => User::query()->where('last_active_at', '>=', now()->subDay())->count(),
            'mau' => User::query()->where('last_active_at', '>=', now()->subDays(30))->count(),
        ];
    }

    /**
     * @return array<string, int|float>
     */
    private function trials(): array
    {
        $active = Subscription::query()
            ->where('status', Subscription::STATUS_TRIAL)
            ->where('trial_ends_at', '>', now())
            ->count();

        $expiringThisWeek = Subscription::query()
            ->where('status', Subscription::STATUS_TRIAL)
            ->whereBetween('trial_ends_at', [now(), now()->addDays(7)])
            ->count();

        $totalSubscriptions = Subscription::query()->count();
        $paid = Subscription::query()
            ->whereIn('status', [Subscription::STATUS_ACTIVE, Subscription::STATUS_GRACE])
            ->count();

        return [
            'active' => $active,
            'expiring_this_week' => $expiringThisWeek,
            'conversion_pct' => $totalSubscriptions > 0 ? round($paid / $totalSubscriptions * 100, 1) : 0.0,
        ];
    }

    /**
     * @return array<string, int|float>
     */
    private function subscriptionMetrics(): array
    {
        $countsByProduct = Subscription::query()
            ->whereIn('status', [Subscription::STATUS_ACTIVE, Subscription::STATUS_GRACE])
            ->whereIn('product_id', array_keys(self::PRODUCT_PRICES))
            ->selectRaw('product_id, count(*) as aggregate')
            ->groupBy('product_id')
            ->pluck('aggregate', 'product_id');

        $mrr = 0.0;
        $monthly = 0;
        $yearly = 0;

        foreach ($countsByProduct as $productId => $count) {
            $isYearly = str_ends_with((string) $productId, '_yearly');
            $mrr += $isYearly ? (self::PRODUCT_PRICES[$productId] / 12) * $count : self::PRODUCT_PRICES[$productId] * $count;

            if ($isYearly) {
                $yearly += $count;
            } else {
                $monthly += $count;
            }
        }

        $payingTotal = $monthly + $yearly;

        $expiredThisMonth = Subscription::query()
            ->where('status', Subscription::STATUS_EXPIRED)
            ->where('updated_at', '>=', now()->startOfMonth())
            ->count();

        return [
            'paying_monthly' => $monthly,
            'paying_yearly' => $yearly,
            'mrr' => round($mrr, 2),
            'arr' => round($mrr * 12, 2),
            'arpu' => $payingTotal > 0 ? round($mrr / $payingTotal, 2) : 0.0,
            'churn_pct' => ($payingTotal + $expiredThisMonth) > 0
                ? round($expiredThisMonth / ($payingTotal + $expiredThisMonth) * 100, 1)
                : 0.0,
            'cancellations_this_month' => $expiredThisMonth,
        ];
    }

    /**
     * @return array<int, array{platform: string, count: int}>
     */
    private function topPlatforms(): array
    {
        return DB::table('store_connections')
            ->selectRaw('platform, count(*) as aggregate')
            ->groupBy('platform')
            ->orderByDesc('aggregate')
            ->get()
            ->map(fn ($row) => ['platform' => (string) $row->platform, 'count' => (int) $row->aggregate])
            ->all();
    }

    /**
     * @return array<string, int>
     */
    private function notificationVolume(): array
    {
        return [
            'push' => Notification::query()->where('type', Notification::TYPE_RULE_PUSH)->count(),
            'email' => Notification::query()->where('type', Notification::TYPE_RULE_EMAIL)->count(),
            'sms' => Notification::query()->where('type', Notification::TYPE_RULE_SMS)->count(),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function smsCostVsRevenue(): array
    {
        return [
            'sent_count' => SmsLedger::query()->where('reason', SmsLedger::REASON_SEND)->count(),
            'topup_credits_purchased' => (int) SmsLedger::query()->where('reason', SmsLedger::REASON_TOPUP_IAP)->sum('delta'),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function funnel(): array
    {
        return [
            'signups' => User::query()->count(),
            'store_connected' => StoreConnection::query()->distinct('team_id')->count('team_id'),
            'push_enabled' => Device::query()->distinct('user_id')->count('user_id'),
            'rule_created' => Rule::query()->distinct('team_id')->count('team_id'),
            'paid' => Subscription::query()
                ->whereIn('status', [Subscription::STATUS_ACTIVE, Subscription::STATUS_GRACE])
                ->distinct('team_id')
                ->count('team_id'),
        ];
    }
}
