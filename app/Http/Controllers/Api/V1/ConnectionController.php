<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Connections\ConnectStoreAction;
use App\Actions\Connections\GetConnectionHealthAction;
use App\Actions\Connections\StartOAuthConnectionAction;
use App\Actions\Connections\TriggerConnectionSyncAction;
use App\Actions\Connections\UpdateConnectionNotificationMuteAction;
use App\Contracts\OAuthChannelAdapter;
use App\Http\Controllers\Controller;
use App\Http\Requests\Connections\ConnectStoreRequest;
use App\Http\Requests\Connections\UpdateConnectionMuteRequest;
use App\Http\Resources\StoreConnectionResource;
use App\Http\Responses\ApiResponse;
use App\Models\StoreConnection;
use App\Models\User;
use App\Support\Connections\ChannelAdapterManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Connections
 *
 * Connect and manage storefront platforms (Shopify, WooCommerce, eBay, Etsy, Amazon, TikTok Shop).
 * WooCommerce, Shopify, eBay, Etsy, and TikTok Shop are all fully live end-to-end today
 * (real, credential-validated connect flows — see each adapter's `connect()`/
 * `completeConnection()`). **Amazon remains the one platform genuinely unconfigured**
 * (no real SP-API credentials in `.env` yet, §15.2) — `POST /connections/amazon/start`
 * always 422s via `AdapterNotReadyException`, not a special case in this controller.
 */
class ConnectionController extends Controller
{
    /**
     * Start connecting a store.
     *
     * `credentials` shape depends on `platform`: WooCommerce expects `{store_url, consumer_key,
     * consumer_secret}` (key-intake, connects immediately). Shopify expects `{shop_domain}`.
     * eBay/Etsy expect no credentials at all. Amazon is still pending adapter approval.
     * OAuth platforms (Shopify/eBay/Etsy) don't connect immediately — this returns an
     * `authorization_url` to open in an in-app browser; the connection itself is created once
     * the merchant approves and the platform redirects back to our callback.
     *
     * @urlParam platform string required One of `shopify`, `woo`, `ebay`, `etsy`, `amazon`, `tiktok`. Example: woo
     *
     * @response 201 scenario="woo — connects immediately" {
     *   "success": true,
     *   "message": null,
     *   "data": {
     *     "connection": {
     *       "id": 1,
     *       "platform": "woo",
     *       "name": "Rivera Vintage Co",
     *       "status": "active",
     *       "last_sync_at": null,
     *       "webhook_status": "registered"
     *     }
     *   }
     * }
     * @response 200 scenario="shopify/ebay/etsy — OAuth redirect" {
     *   "success": true,
     *   "message": null,
     *   "data": {
     *     "authorization_url": "https://my-shop.myshopify.com/admin/oauth/authorize?client_id=..."
     *   }
     * }
     * @response 422 scenario="profile setup incomplete" {
     *   "success": false,
     *   "message": "Complete profile setup before connecting a store.",
     *   "errors": null
     * }
     */
    public function start(
        ConnectStoreRequest $request,
        string $platform,
        ConnectStoreAction $action,
        StartOAuthConnectionAction $startOAuth,
        ChannelAdapterManager $adapters,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();
        $team = $user->currentTeam();

        if ($team === null) {
            return ApiResponse::error('Complete profile setup before connecting a store.', status: 422);
        }

        $name = $request->string('name')->toString();
        $credentials = $request->input('credentials', []);

        if ($adapters->driver($platform) instanceof OAuthChannelAdapter) {
            $url = $startOAuth->handle($team, $platform, $name, $credentials);

            return ApiResponse::success(['authorization_url' => $url]);
        }

        $connection = $action->handle(
            team: $team,
            platform: $platform,
            name: $name,
            credentials: $credentials,
        );

        return ApiResponse::success(['connection' => new StoreConnectionResource($connection)], status: 201);
    }

    /**
     * List connections.
     *
     * @response 200 scenario="success" {
     *   "success": true,
     *   "message": null,
     *   "data": {
     *     "connections": [
     *       {
     *         "id": 1,
     *         "platform": "woo",
     *         "name": "Rivera Vintage Co",
     *         "status": "active",
     *         "last_sync_at": "2026-07-16T01:45:00.000000Z",
     *         "webhook_status": "registered"
     *       }
     *     ]
     *   }
     * }
     */
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $team = $user->currentTeam();

        $connections = $team === null
            ? collect()
            : StoreConnection::query()->where('team_id', $team->id)->get();

        return ApiResponse::success(['connections' => StoreConnectionResource::collection($connections)]);
    }

    /**
     * Disconnect a store.
     *
     * @response 200 scenario="success" {
     *   "success": true,
     *   "message": "Store disconnected.",
     *   "data": null
     * }
     */
    public function destroy(Request $request, StoreConnection $connection): JsonResponse
    {
        $this->authorizeConnectionAccess($request, $connection);

        $connection->delete();

        return ApiResponse::success(message: 'Store disconnected.');
    }

    /**
     * Get connection health.
     *
     * Plain-language status for the connection-health screen — never raw error codes.
     * `fix_action` is a key the client maps to a concrete flow (e.g. `reauth`), not a URL.
     *
     * @response 200 scenario="healthy" {
     *   "success": true,
     *   "message": null,
     *   "data": {
     *     "connection_id": 1,
     *     "status": "active",
     *     "webhook_status": "registered",
     *     "last_sync_at": "2026-07-16T01:45:00+00:00",
     *     "message": "Rivera Vintage Co is connected and syncing normally.",
     *     "fix_action": null
     *   }
     * }
     * @response 200 scenario="needs reauth" {
     *   "success": true,
     *   "message": null,
     *   "data": {
     *     "connection_id": 1,
     *     "status": "needs_reauth",
     *     "webhook_status": "registered",
     *     "last_sync_at": "2026-07-15T20:00:00+00:00",
     *     "message": "Your connection to Rivera Vintage Co needs to be reconnected.",
     *     "fix_action": "reauth"
     *   }
     * }
     */
    public function health(Request $request, StoreConnection $connection, GetConnectionHealthAction $action): JsonResponse
    {
        $this->authorizeConnectionAccess($request, $connection);

        return ApiResponse::success($action->handle($connection));
    }

    /**
     * Mute or unmute alerts from this store.
     *
     * The only editable field on an existing connection today — syncing continues either way,
     * this only stops push/email/SMS from rules whose firing traces back to this store. Rule
     * fires are still logged in the notification center regardless (Plan §4.8/§17.4's "logged
     * but not delivered" convention).
     *
     * @response 200 scenario="success" {
     *   "success": true,
     *   "message": null,
     *   "data": {
     *     "connection": {
     *       "id": 1,
     *       "platform": "woo",
     *       "name": "Rivera Vintage Co",
     *       "status": "active",
     *       "notifications_muted": true,
     *       "last_sync_at": "2026-07-16T01:45:00.000000Z",
     *       "webhook_status": "registered"
     *     }
     *   }
     * }
     */
    public function mute(UpdateConnectionMuteRequest $request, StoreConnection $connection, UpdateConnectionNotificationMuteAction $action): JsonResponse
    {
        $this->authorizeConnectionAccess($request, $connection);

        $connection = $action->handle($connection, $request->boolean('notifications_muted'));

        return ApiResponse::success(['connection' => new StoreConnectionResource($connection)]);
    }

    /**
     * Sync this store now.
     *
     * A manual "sync now" trigger for the Connections list screen — dispatches the same
     * order-poll job the automatic scheduler and connect-time first-sync already use, just
     * on demand. This is deliberately per-connection, not a "refresh everything" action: each
     * connection has its own 60-second cooldown (every platform's API has its own per-store
     * rate limit repeated manual triggers could otherwise burn through), so a team with
     * multiple stores can sync one without waiting on another's cooldown.
     *
     * Pull-to-refresh on the Feed/Orders list should NOT call this — that should just refetch
     * from `GET /orders` (this app's own database), the same as every other pull-to-refresh
     * pattern. This endpoint is for the Connections screen specifically, where "go check with
     * the platform right now" is the actual intent.
     *
     * @response 200 scenario="sync started" {
     *   "success": true,
     *   "message": "Sync started.",
     *   "data": null
     * }
     * @response 429 scenario="cooldown active" {
     *   "success": false,
     *   "message": "Sync already requested recently — try again in 42 seconds.",
     *   "errors": null
     * }
     */
    public function syncNow(Request $request, StoreConnection $connection, TriggerConnectionSyncAction $action): JsonResponse
    {
        $this->authorizeConnectionAccess($request, $connection);

        $result = $action->handle($connection);

        if (! $result['dispatched']) {
            return ApiResponse::error(
                "Sync already requested recently — try again in {$result['retry_after_seconds']} seconds.",
                status: 429,
            );
        }

        return ApiResponse::success(message: 'Sync started.');
    }

    private function authorizeConnectionAccess(Request $request, StoreConnection $connection): void
    {
        /** @var User $user */
        $user = $request->user();

        if ($connection->team_id !== $user->currentTeam()?->id) {
            abort(404);
        }
    }
}
