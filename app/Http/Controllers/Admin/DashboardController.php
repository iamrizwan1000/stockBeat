<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\ComputeDashboardKpisAction;
use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(ComputeDashboardKpisAction $action): Response
    {
        return Inertia::render('admin/dashboard', [
            'kpis' => $action->handle(),
        ]);
    }
}
