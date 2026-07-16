<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\ExtendTrialAction;
use App\Actions\Admin\ForceLogoutAction;
use App\Actions\Admin\GrantBonusSmsCreditsAction;
use App\Actions\Admin\GrantComplimentaryProAction;
use App\Actions\Admin\SuspendAccountAction;
use App\Actions\Admin\UnsuspendAccountAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\GrantDaysRequest;
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

    public function grantSmsCredits(GrantSmsCreditsRequest $request, User $user, GrantBonusSmsCreditsAction $action): RedirectResponse
    {
        $team = $this->requireTeam($user);

        $action->handle($this->admin($request), $team, (int) $request->input('credits'));

        return back()->with('status', 'SMS credits granted.');
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
}
