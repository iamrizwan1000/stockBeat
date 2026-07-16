<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Connections\ConnectStoreAction;
use App\Actions\Connections\GetConnectionHealthAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Connections\ConnectStoreRequest;
use App\Http\Resources\StoreConnectionResource;
use App\Http\Responses\ApiResponse;
use App\Models\StoreConnection;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConnectionController extends Controller
{
    public function start(ConnectStoreRequest $request, string $platform, ConnectStoreAction $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $team = $user->currentTeam();

        if ($team === null) {
            return ApiResponse::error('Complete profile setup before connecting a store.', status: 422);
        }

        $connection = $action->handle(
            team: $team,
            platform: $platform,
            name: $request->string('name')->toString(),
            credentials: $request->input('credentials', []),
        );

        return ApiResponse::success(['connection' => new StoreConnectionResource($connection)], status: 201);
    }

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

    public function destroy(Request $request, StoreConnection $connection): JsonResponse
    {
        $this->authorizeConnectionAccess($request, $connection);

        $connection->delete();

        return ApiResponse::success(message: 'Store disconnected.');
    }

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
