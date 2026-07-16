<?php

namespace App\Http\Controllers\Admin;

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
    public function index(Request $request, ListCustomersAction $action): Response
    {
        $filters = $request->only(['q', 'plan', 'platform', 'signup_from', 'signup_to', 'last_active_from']);
        $customers = $action->handle($filters);

        return Inertia::render('admin/customers/index', [
            'filters' => $filters,
            'customers' => [
                'data' => collect($customers->items())->map(fn (User $user) => $this->summarize($user))->all(),
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

    public function exportCsv(Request $request, ListCustomersAction $action): StreamedResponse
    {
        $filters = $request->only(['q', 'plan', 'platform', 'signup_from', 'signup_to', 'last_active_from']);
        $customers = $action->handle($filters);

        return response()->streamDownload(function () use ($customers) {
            $handle = fopen('php://output', 'w');

            if ($handle === false) {
                return;
            }

            fputcsv($handle, ['ID', 'Name', 'Email', 'Business name', 'Plan', 'Signed up', 'Last active']);

            foreach ($customers->items() as $user) {
                /** @var User $user */
                $summary = $this->summarize($user);
                fputcsv($handle, [
                    $summary['id'],
                    $summary['name'],
                    $summary['email'],
                    $summary['business_name'],
                    $summary['plan_status'],
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
    private function summarize(User $user): array
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
            'created_at' => $user->created_at,
            'last_active_at' => $user->last_active_at,
            'suspended_at' => $user->suspended_at,
        ];
    }
}
