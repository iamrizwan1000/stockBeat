<?php

use App\Exceptions\Ai\AiProviderException;
use App\Exceptions\Connections\AdapterNotReadyException;
use App\Http\Middleware\EnsureAdminCanWrite;
use App\Http\Middleware\EnsureAdminHasTwoFactorEnabled;
use App\Http\Middleware\EnsureTeamMemberIsNotSuspended;
use App\Http\Middleware\EnsureTeamRole;
use App\Http\Middleware\EnsureUserIsNotSuspended;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Responses\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Sentry\Laravel\Integration as SentryIntegration;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            // Webhook ingestion is a separate ingress from /api/v1 (Plan
            // §17.7) — stateless 'api' middleware (no CSRF), no Sanctum
            // auth. Each platform's own signature scheme is the boundary.
            Route::middleware('api')->group(base_path('routes/webhooks.php'));

            // Broadcasting auth (support inbox, Plan §4.9/§8.7.6): both the
            // mobile app (Sanctum bearer token) and the admin panel (session
            // guard 'admin') need to authorize private support-thread
            // channels, so both guards are tried against the same endpoint
            // rather than registering it twice — not the framework's
            // `channels:` shortcut (which only wires the default 'web'
            // guard, unused by either real caller in this app).
            Broadcast::routes(['middleware' => ['auth:admin,sanctum']]);
            require base_path('routes/channels.php');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->alias([
            'admin.write' => EnsureAdminCanWrite::class,
            'admin.2fa' => EnsureAdminHasTwoFactorEnabled::class,
            'user.not_suspended' => EnsureUserIsNotSuspended::class,
            'team.not_suspended' => EnsureTeamMemberIsNotSuspended::class,
            'team.role' => EnsureTeamRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Sends every reported exception (including manual report($e) calls,
        // e.g. OAuthCallbackController's generic connection-failure catch)
        // to Sentry so failures are visible in a dashboard instead of only
        // in laravel.log.
        SentryIntegration::handles($exceptions);

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Every /api/v1 response — success or failure — shares the same
        // {success, message, data|errors} envelope (Plan §10).
        $exceptions->render(function (ValidationException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error($e->getMessage(), $e->errors(), $e->status);
            }
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error($e->getMessage(), status: 401);
            }
        });

        $exceptions->render(function (AuthorizationException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error($e->getMessage(), status: 403);
            }
        });

        $exceptions->render(function (NotFoundHttpException|ModelNotFoundException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error('Not found.', status: 404);
            }
        });

        $exceptions->render(function (TooManyRequestsHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error('Too many requests.', status: 429);
            }
        });

        $exceptions->render(function (AdapterNotReadyException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error($e->getMessage(), status: 422);
            }
        });

        // Plan §4.12: a provider outage/bad-key/malformed-response never
        // debits the team's question quota (AskAssistantAction only debits
        // after a successful answer) — this just surfaces it as a real,
        // honest failure rather than a 500.
        $exceptions->render(function (AiProviderException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error($e->getMessage(), status: 502);
            }
        });
    })->create();
