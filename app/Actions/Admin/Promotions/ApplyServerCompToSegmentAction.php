<?php

namespace App\Actions\Admin\Promotions;

use App\Actions\Admin\AuditLogAction;
use App\Actions\Admin\GrantBonusSmsCreditsAction;
use App\Actions\Admin\GrantComplimentaryProAction;
use App\Actions\Admin\Messaging\ResolveSegmentAudienceAction;
use App\Models\AdminUser;
use App\Models\PromoCampaign;
use App\Models\PromoCampaignRedemption;
use App\Models\Segment;
use Illuminate\Validation\ValidationException;

/**
 * Executes a `server_comp` campaign's "apply to segment" action (Plan
 * §8.7.4): grants every team in the resolved audience a complimentary Pro
 * extension or a bonus SMS credit top-up, per the campaign's `config`.
 * Applying to "everyone" (no segment) requires a superadmin — same
 * guardrail as `SendBroadcastAction`'s all-users send.
 *
 * Also stamps a `PromoCampaignRedemption` row per team (updating
 * `redeemed_at` if the team already redeemed this campaign before) — the
 * concrete "this team was targeted by this campaign" linkage
 * `ComputeCampaignStatsAction` reads to turn `stats` into real numbers.
 */
class ApplyServerCompToSegmentAction
{
    public function __construct(
        private readonly ResolveSegmentAudienceAction $resolveAudience,
        private readonly GrantComplimentaryProAction $grantPro,
        private readonly GrantBonusSmsCreditsAction $grantSmsCredits,
        private readonly AuditLogAction $auditLog,
    ) {}

    public function handle(AdminUser $admin, PromoCampaign $campaign, ?int $segmentId): PromoCampaign
    {
        if ($campaign->type !== PromoCampaign::TYPE_SERVER_COMP) {
            throw ValidationException::withMessages(['campaign' => 'Only server_comp campaigns can be applied to a segment.']);
        }

        $compType = $campaign->config['comp_type'] ?? null;
        $amount = (int) ($campaign->config['amount'] ?? 0);

        if (! in_array($compType, [PromoCampaign::COMP_TYPE_PRO_DAYS, PromoCampaign::COMP_TYPE_SMS_CREDITS], true) || $amount <= 0) {
            throw ValidationException::withMessages(['campaign' => 'This campaign has no valid comp_type/amount configured.']);
        }

        if ($segmentId === null && $admin->role !== AdminUser::ROLE_SUPERADMIN) {
            throw ValidationException::withMessages(['campaign' => 'Only a superadmin can apply a comp to all users.']);
        }

        $filters = $segmentId !== null ? Segment::query()->findOrFail($segmentId)->filters : null;
        $users = $this->resolveAudience->handle($filters)->with('ownedTeam')->get();

        $teams = $users->map(fn ($user) => $user->ownedTeam)->filter()->unique('id');

        foreach ($teams as $team) {
            if ($compType === PromoCampaign::COMP_TYPE_PRO_DAYS) {
                $this->grantPro->handle($admin, $team, $amount);
            } else {
                $this->grantSmsCredits->handle($admin, $team, $amount);
            }

            PromoCampaignRedemption::query()->updateOrCreate(
                ['promo_campaign_id' => $campaign->id, 'team_id' => $team->id],
                ['redeemed_at' => now()],
            );
        }

        $applications = $campaign->stats['applications'] ?? [];
        $applications[] = [
            'segment_id' => $segmentId,
            'recipients_total' => $teams->count(),
            'applied_at' => now()->toIso8601String(),
        ];

        $campaign->update([
            'stats' => [
                'applications' => $applications,
                'recipients_total_all_time' => array_sum(array_column($applications, 'recipients_total')),
            ],
        ]);

        $this->auditLog->handle($admin, 'promo_campaign.apply_server_comp', PromoCampaign::class, $campaign->id, null, [
            'segment_id' => $segmentId,
            'recipients_total' => $teams->count(),
        ]);

        return $campaign->fresh();
    }
}
