<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Connections\ConnectStoreAction;
use App\Actions\Connections\GetConnectionHealthAction;
use App\Actions\Connections\StartOAuthConnectionAction;
use App\Contracts\OAuthChannelAdapter;
use App\Http\Controllers\Controller;
use App\Http\Requests\Connections\ConnectStoreRequest;
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
 * Connect and manage storefront platforms (Shopify, WooCommerce, eBay, Etsy, Amazon).
 * Only WooCommerce is fully live end-to-end today — the others accept a connection
 * record but adapter operations are not yet available.
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
     * @urlParam platform string required One of `shopify`, `woo`, `ebay`, `etsy`, `amazon`. Example: woo
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

    private function authorizeConnectionAccess(Request $request, StoreConnection $connection): void
    {
        /** @var User $user */
        $user = $request->user();

        if ($connection->team_id !== $user->currentTeam()?->id) {
            abort(404);
        }
    }
}
