<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\ExtendTrialAction;
use App\Actions\Admin\ForceLogoutAction;
use App\Actions\Admin\GrantBonusAiCreditsAction;
use App\Actions\Admin\GrantBonusEmailCreditsAction;
use App\Actions\Admin\GrantBonusSmsCreditsAction;
use App\Actions\Admin\GrantComplimentaryProAction;
use App\Actions\Admin\NotifyCustomerOfCreditGrantAction;
use App\Actions\Admin\SuspendAccountAction;
use App\Actions\Admin\UnsuspendAccountAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\GrantAiCreditsRequest;
use App\Http\Requests\Admin\GrantDaysRequest;
use App\Http\Requests\Admin\GrantEmailCreditsRequest;
use App\Http\Requests\Admin\GrantSmsCreditsRequest;
use App\Models\AdminUser;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CustomerActionController extends Controller
{
    public function extendTrial(GrantDaysRequest $request, User $user, ExtendTrialAction $action): RedirectResponse
    {
        $team = $this->requireTeam($user);

        $action->handle($this->admin($request), $team, (int) $request->input('days'));

        return back()->with('status', 'Trial extended.');
    }

    public function grantPro(GrantDaysRequest $request, User $user, GrantComplimentaryProAction $action): RedirectResponse
    {
        $team = $this->requireTeam($user);

        $action->handle($this->admin($request), $team, (int) $request->input('days'));

        return back()->with('status', 'Complimentary Pro granted.');
    }

    public function grantSmsCredits(GrantSmsCreditsRequest $request, User $user, GrantBonusSmsCreditsAction $action, NotifyCustomerOfCreditGrantAction $notify): RedirectResponse
    {
        $team = $this->requireTeam($user);
        $credits = (int) $request->input('credits');

        $action->handle($this->admin($request), $team, $credits);
        $this->notifyIfRequested($request, $notify, $user, $team, 'SMS', $credits);

        return back()->with('status', 'SMS credits granted.');
    }

    public function grantAiCredits(GrantAiCreditsRequest $request, User $user, GrantBonusAiCreditsAction $action, NotifyCustomerOfCreditGrantAction $notify): RedirectResponse
    {
        $team = $this->requireTeam($user);
        $credits = (int) $request->input('credits');

        $action->handle($this->admin($request), $team, $credits);
        $this->notifyIfRequested($request, $notify, $user, $team, 'AI question', $credits);

        return back()->with('status', 'AI question credits granted.');
    }

    public function grantEmailCredits(GrantEmailCreditsRequest $request, User $user, GrantBonusEmailCreditsAction $action, NotifyCustomerOfCreditGrantAction $notify): RedirectResponse
    {
        $team = $this->requireTeam($user);
        $credits = (int) $request->input('credits');

        $action->handle($this->admin($request), $team, $credits);
        $this->notifyIfRequested($request, $notify, $user, $team, 'email', $credits);

        return back()->with('status', 'Email credits granted.');
    }

    public function forceLogout(Request $request, User $user, ForceLogoutAction $action): RedirectResponse
    {
        $action->handle($this->admin($request), $user);

        return back()->with('status', 'User logged out of all devices.');
    }

    public function suspend(Request $request, User $user, SuspendAccountAction $action): RedirectResponse
    {
        $action->handle($this->admin($request), $user);

        return back()->with('status', 'Account suspended.');
    }

    public function unsuspend(Request $request, User $user, UnsuspendAccountAction $action): RedirectResponse
    {
        $action->handle($this->admin($request), $user);

        return back()->with('status', 'Account unsuspended.');
    }

    private function admin(Request $request): AdminUser
    {
        /** @var AdminUser $admin */
        $admin = $request->user('admin');

        return $admin;
    }

    private function requireTeam(User $user): Team
    {
        $team = $user->ownedTeam;

        abort_if($team === null, 422, 'This user has not completed profile setup yet.');

        return $team;
    }

    private function notifyIfRequested(
        Request $request,
        NotifyCustomerOfCreditGrantAction $notify,
        User $user,
        Team $team,
        string $creditType,
        int $credits,
    ): void {
        if (! $request->boolean('notify_customer')) {
            return;
        }

        $notify->handle(
            $this->admin($request),
            $user,
            $team,
            (array) $request->input('notify_channels', []),
            $creditType,
            $credits,
            (string) $request->input('notify_note', ''),
        );
    }
}
