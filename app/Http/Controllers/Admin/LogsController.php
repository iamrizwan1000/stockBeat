<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\ReadRecentLogAction;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LogsController extends Controller
{
    /**
     * Admins can pick how much of the tail to load — options are capped
     * server-side so a crafted query param can't force a huge read.
     */
    private const ALLOWED_BYTE_OPTIONS = [50_000, 200_000, 500_000, 2_000_000];

    public function index(Request $request, ReadRecentLogAction $action): Response
    {
        $maxBytes = (int) $request->integer('bytes', 200_000);

        if (! in_array($maxBytes, self::ALLOWED_BYTE_OPTIONS, true)) {
            $maxBytes = 200_000;
        }

        return Inertia::render('admin/logs/index', [
            'log' => $action->handle($maxBytes),
            'selected_bytes' => $maxBytes,
            'byte_options' => self::ALLOWED_BYTE_OPTIONS,
        ]);
    }
}
