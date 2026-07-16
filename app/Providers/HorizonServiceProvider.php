<?php

namespace App\Providers;

use App\Models\AdminUser;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();

        // Horizon::routeSmsNotificationsTo('15556667777');
        // Horizon::routeMailNotificationsTo('example@example.com');
        // Horizon::routeSlackNotificationsTo('slack-webhook-url', '#channel');
    }

    /**
     * Register the Horizon gate.
     *
     * This gate determines who can access Horizon in non-local environments.
     * Horizon resolves `$user` from the default guard, which this app doesn't
     * use for anyone — the admin panel authenticates on a separate `admin`
     * guard, so that's checked directly instead of trusting the parameter.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', function ($user = null) {
            /** @var AdminUser|null $admin */
            $admin = Auth::guard('admin')->user();

            return $admin !== null && $admin->role === AdminUser::ROLE_SUPERADMIN;
        });
    }
}
