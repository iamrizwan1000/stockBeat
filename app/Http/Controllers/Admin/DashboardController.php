<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\ComputeDashboardKpisAction;
use App\Actions\Admin\ComputeFeatureAdoptionAction;
use App\Actions\Admin\ComputePaywallHitsSnapshotAction;
use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(
        ComputeDashboardKpisAction $kpis,
        ComputeFeatureAdoptionAction $featureAdoption,
        ComputePaywallHitsSnapshotAction $paywallHits,
    ): Response {
        return Inertia::render('admin/dashboard', [
            'kpis' => $kpis->handle(),
            'featureAdoption' => $featureAdoption->handle(),
            'paywallHits' => $paywallHits->handle(),
        ]);
    }
}
