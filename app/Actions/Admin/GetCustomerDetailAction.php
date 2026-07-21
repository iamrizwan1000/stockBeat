<?php

namespace App\Actions\Admin;

use App\Actions\Billing\ResolveEntitlementsAction;
use App\Models\AiUsageLedger;
use App\Models\Device;
use App\Models\Notification;
use App\Models\SmsLedger;
use App\Models\SubscriptionEvent;
use App\Models\SupportMessage;
use App\Models\SupportThread;
use App\Models\Team;
use App\Models\User;

/**
 * Plan §8.7.2 customer detail page. Subscription timeline, LTV, and
 * trial-abuse/high-SMS-cost flags (added in the same pass this docblock note
 * was updated) come from `subscription_events`, `ComputeCustomerLtvAction`,
 * and `DetectAccountAbuseSignalsAction` respectively — see those classes for
 * the honest gaps in each (not every RevenueCat event carries a price; abuse
 * flags are best-effort signals, not proof). Support-chat history (added
 * 2026-07-22) is a real read of the same `support_threads`/`support_messages`
 * tables the standalone Support Inbox page (§8.7.6) uses — a summary here,
 * a link to the full thread there, not a duplicated data source.
 */
class GetCustomerDetailAction
{
    private const SUBSCRIPTION_TIMELINE_LIMIT = 50;

    public function __construct(
        private readonly ResolveEntitlementsAction $resolveEntitlements,
        private readonly ComputeCustomerLtvAction $computeLtv,
        private readonly DetectAccountAbuseSignalsAction $detectAbuseSignals,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(User $user): array
    {
        $team = $user->ownedTeam()->with(['storeConnections', 'rules', 'subscription'])->first();

        $devices = Device::query()->where('user_id', $user->id)->get();

        return [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'business_name' => $user->business_name,
                'base_currency' => $user->base_currency,
                'timezone' => $user->timezone,
                'sells_on' => $user->sells_on,
                'suspended_at' => $user->suspended_at,
                'created_at' => $user->created_at,
                'last_active_at' => $user->last_active_at,
            ],
            'team' => $team === null ? null : [
                'id' => $team->id,
                'name' => $team->name,
            ],
            'entitlements' => $team === null ? null : $this->resolveEntitlements->handle($team),
            'subscription' => $team?->subscription === null ? null : [
                'status' => $team->subscription->status,
                'product_id' => $team->subscription->product_id,
                'provider' => $team->subscription->provider,
                'trial_ends_at' => $team->subscription->trial_ends_at,
                'expires_at' => $team->subscription->expires_at,
                'renewed_at' => $team->subscription->renewed_at,
            ],
            'devices' => $devices->map(fn (Device $device) => [
                'id' => $device->id,
                'platform' => $device->platform,
                'last_seen_at' => $device->last_seen_at,
            ])->all(),
            'store_connections' => $team === null ? [] : $team->storeConnections->map(fn ($connection) => [
                'id' => $connection->id,
                'platform' => $connection->platform,
                'name' => $connection->name,
                'status' => $connection->status,
                'last_sync_at' => $connection->last_sync_at,
                'webhook_status' => $connection->webhook_status,
            ])->all(),
            'rules' => $team === null ? [] : $team->rules->map(fn ($rule) => [
                'id' => $rule->id,
                'name' => $rule->name,
                'trigger' => $rule->trigger,
                'enabled' => $rule->enabled,
            ])->all(),
            'sms_ledger' => $team === null ? [] : SmsLedger::query()
                ->where('team_id', $team->id)
                ->latest('id')
                ->limit(20)
                ->get()
                ->map(fn (SmsLedger $entry) => [
                    'id' => $entry->id,
                    'delta' => $entry->delta,
                    'reason' => $entry->reason,
                    'balance_after' => $entry->balance_after,
                    'created_at' => $entry->created_at,
                ])->all(),
            'ai_usage' => $team === null ? null : [
                'questions_used_this_month' => AiUsageLedger::questionsUsedThisMonth($team->id),
                'monthly_limit' => AiUsageLedger::effectiveMonthlyLimit($team->id, $this->resolveEntitlements->handle($team)['limits']['ai_questions_monthly'] ?? null),
                'bonus_granted_this_month' => AiUsageLedger::bonusGrantedThisMonth($team->id),
                'ledger' => AiUsageLedger::query()
                    ->where('team_id', $team->id)
                    ->latest('id')
                    ->limit(20)
                    ->get()
                    ->map(fn (AiUsageLedger $entry) => [
                        'id' => $entry->id,
                        'delta' => $entry->delta,
                        'reason' => $entry->reason,
                        'balance_after' => $entry->balance_after,
                        'created_at' => $entry->created_at,
                    ])->all(),
            ],
            'notification_volume' => [
                'push' => Notification::query()->where('user_id', $user->id)->where('type', Notification::TYPE_RULE_PUSH)->count(),
                'email' => Notification::query()->where('user_id', $user->id)->where('type', Notification::TYPE_RULE_EMAIL)->count(),
                'sms' => Notification::query()->where('user_id', $user->id)->where('type', Notification::TYPE_RULE_SMS)->count(),
            ],
            'funnel_position' => $this->funnelPosition($user, $team),
            'subscription_timeline' => $team === null ? [] : SubscriptionEvent::query()
                ->where('team_id', $team->id)
                ->orderByDesc('occurred_at')
                ->limit(self::SUBSCRIPTION_TIMELINE_LIMIT)
                ->get()
                ->map(fn (SubscriptionEvent $event) => [
                    'id' => $event->id,
                    'event_type' => $event->event_type,
                    'price' => $event->price,
                    'currency' => $event->currency,
                    'occurred_at' => $event->occurred_at,
                ])->all(),
            'ltv' => $team === null ? null : $this->computeLtv->handle($team),
            'abuse_flags' => $team === null
                ? ['trial_abuse_suspected' => false, 'high_sms_cost' => false]
                : $this->detectAbuseSignals->handle($team),
            'support_thread' => $this->supportThreadSummary($user),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function supportThreadSummary(User $user): ?array
    {
        $thread = SupportThread::query()->where('user_id', $user->id)->first();

        if ($thread === null) {
            return null;
        }

        return [
            'id' => $thread->id,
            'status' => $thread->status,
            'priority' => $thread->priority,
            'last_message_at' => $thread->last_message_at,
            'csat' => $thread->csat,
            'recent_messages' => SupportMessage::query()
                ->where('thread_id', $thread->id)
                ->latest('id')
                ->limit(5)
                ->get()
                ->reverse()
                ->values()
                ->map(fn (SupportMessage $message) => [
                    'id' => $message->id,
                    'direction' => $message->direction,
                    'body' => $message->body,
                    'created_at' => $message->created_at,
                ])->all(),
        ];
    }

    private function funnelPosition(User $user, ?Team $team): string
    {
        if ($team === null) {
            return 'signed_up';
        }

        if ($team->subscription !== null && in_array($team->subscription->status, ['active', 'grace'], true)) {
            return 'paid';
        }

        if ($team->rules->isNotEmpty()) {
            return 'rule_created';
        }

        if (Device::query()->where('user_id', $user->id)->exists()) {
            return 'push_enabled';
        }

        if ($team->storeConnections->isNotEmpty()) {
            return 'store_connected';
        }

        return 'signed_up';
    }
}
