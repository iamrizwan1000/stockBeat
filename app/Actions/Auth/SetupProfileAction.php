<?php

namespace App\Actions\Auth;

use App\Actions\Billing\GrantTrialSubscriptionAction;
use App\Actions\Teams\RedeemTeamInviteAction;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;

/**
 * Completes onboarding Screen 3 (Plan §4.1): sets the user's profile and,
 * for a brand-new user, either redeems a pending team invite (Plan §4.7 —
 * joining as a member if this email was invited) or creates their own
 * owning Team — the container every other domain table (connections,
 * orders, rules, billing) hangs off — and grants it the signup trial
 * (§6.3). Idempotent: calling it again only updates the profile, never
 * creates a second team, membership, or trial.
 */
class SetupProfileAction
{
    public function __construct(
        private readonly GrantTrialSubscriptionAction $grantTrialSubscription,
        private readonly RedeemTeamInviteAction $redeemTeamInvite,
    ) {}

    /**
     * @param  array<int, string>  $sellsOn
     */
    public function handle(
        User $user,
        string $name,
        ?string $businessName,
        array $sellsOn,
        ?string $timezone,
        ?string $baseCurrency,
        ?string $phone = null,
    ): User {
        $user->name = $name;
        $user->business_name = $businessName;
        $user->sells_on = $sellsOn;

        if ($phone !== null) {
            $user->phone = $phone;
        }

        if ($timezone !== null) {
            $user->timezone = $timezone;
        }

        if ($baseCurrency !== null) {
            $user->base_currency = strtoupper($baseCurrency);
        }

        $user->save();

        if (! $user->teamMemberships()->exists() && ! $this->redeemTeamInvite->handle($user)) {
            $team = Team::query()->create([
                'owner_id' => $user->id,
                'name' => $businessName ?: $name,
            ]);

            TeamMember::query()->create([
                'team_id' => $team->id,
                'user_id' => $user->id,
                'role' => TeamMember::ROLE_OWNER,
            ]);

            $this->grantTrialSubscription->handle($team);
        }

        return $user;
    }
}
