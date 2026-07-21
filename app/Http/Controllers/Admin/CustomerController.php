<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\ComputeCustomerLtvAction;
use App\Actions\Admin\GetCustomerDetailAction;
use App\Actions\Admin\ListCustomersAction;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CustomerController extends Controller
{
    private const FILTER_KEYS = ['q', 'plan', 'platform', 'country', 'signup_from', 'signup_to', 'last_active_from', 'ltv_min', 'ltv_max'];

    public function index(Request $request, ListCustomersAction $action, ComputeCustomerLtvAction $computeLtv): Response
    {
        $filters = $request->only(self::FILTER_KEYS);
        $customers = $action->handle($filters);

        return Inertia::render('admin/customers/index', [
            'filters' => $filters,
            'customers' => [
                'data' => collect($customers->items())->map(fn (User $user) => $this->summarize($user, $computeLtv))->all(),
                'current_page' => $customers->currentPage(),
                'last_page' => $customers->lastPage(),
                'total' => $customers->total(),
            ],
        ]);
    }

    public function show(User $user, GetCustomerDetailAction $action): Response
    {
        return Inertia::render('admin/customers/show', [
            'customer' => $action->handle($user),
        ]);
    }

    public function exportCsv(Request $request, ListCustomersAction $action, ComputeCustomerLtvAction $computeLtv): StreamedResponse
    {
        $filters = $request->only(self::FILTER_KEYS);
        $customers = $action->handle($filters);

        return response()->streamDownload(function () use ($customers, $computeLtv) {
            $handle = fopen('php://output', 'w');

            if ($handle === false) {
                return;
            }

            fputcsv($handle, ['ID', 'Name', 'Email', 'Business name', 'Plan', 'LTV', 'Signed up', 'Last active']);

            foreach ($customers->items() as $user) {
                /** @var User $user */
                $summary = $this->summarize($user, $computeLtv);
                fputcsv($handle, [
                    $summary['id'],
                    $summary['name'],
                    $summary['email'],
                    $summary['business_name'],
                    $summary['plan_status'],
                    $summary['ltv'],
                    $summary['created_at'],
                    $summary['last_active_at'],
                ]);
            }

            fclose($handle);
        }, 'customers.csv', ['Content-Type' => 'text/csv']);
    }

    /**
     * @return array<string, mixed>
     */
    private function summarize(User $user, ComputeCustomerLtvAction $computeLtv): array
    {
        $team = $user->ownedTeam;
        $planStatus = 'free';

        if ($team !== null && $team->subscription !== null) {
            $planStatus = $team->subscription->status;
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'business_name' => $user->business_name,
            'plan_status' => $planStatus,
            'platforms' => $team === null ? [] : $team->storeConnections->pluck('platform')->unique()->values()->all(),
            'ltv' => $team === null ? null : $computeLtv->handle($team)['total'],
            'created_at' => $user->created_at,
            'last_active_at' => $user->last_active_at,
            'suspended_at' => $user->suspended_at,
        ];
    }
}
