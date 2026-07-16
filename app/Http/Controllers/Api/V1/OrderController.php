<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Orders\AddOrderNoteAction;
use App\Actions\Orders\CancelOrderAction;
use App\Actions\Orders\FulfillOrderAction;
use App\Actions\Orders\GeneratePackingSlipAction;
use App\Actions\Orders\ListOrdersAction;
use App\Actions\Orders\RefundOrderAction;
use App\Actions\Orders\SnoozeOrderAction;
use App\Actions\Orders\UpdateOrderTagsAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Orders\AddOrderNoteRequest;
use App\Http\Requests\Orders\CancelOrderRequest;
use App\Http\Requests\Orders\FulfillOrderRequest;
use App\Http\Requests\Orders\ListOrdersRequest;
use App\Http\Requests\Orders\RefundOrderRequest;
use App\Http\Requests\Orders\SnoozeOrderRequest;
use App\Http\Requests\Orders\UpdateOrderTagsRequest;
use App\Http\Resources\OrderNoteResource;
use App\Http\Resources\OrderResource;
use App\Http\Responses\ApiResponse;
use App\Models\Order;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;

class OrderController extends Controller
{
    public function index(ListOrdersRequest $request, ListOrdersAction $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $team = $user->currentTeam();

        if ($team === null) {
            return ApiResponse::success(['orders' => [], 'next_cursor' => null]);
        }

        $orders = $action->handle($team, $request->validated(), $user->currentTeamMember());

        return ApiResponse::success([
            'orders' => OrderResource::collection($orders->items()),
            'next_cursor' => $orders->nextCursor()?->encode(),
        ]);
    }

    public function show(Request $request, Order $order): JsonResponse
    {
        $this->authorizeOrderAccess($request, $order);

        $order->load(['items', 'notes']);

        return ApiResponse::success(['order' => new OrderResource($order)]);
    }

    public function addNote(AddOrderNoteRequest $request, Order $order, AddOrderNoteAction $action): JsonResponse
    {
        $this->authorizeOrderAccess($request, $order);

        /** @var User $user */
        $user = $request->user();

        $note = $action->handle($order, $user, $request->string('body')->toString());

        return ApiResponse::success(['note' => new OrderNoteResource($note)], status: 201);
    }

    public function updateTags(UpdateOrderTagsRequest $request, Order $order, UpdateOrderTagsAction $action): JsonResponse
    {
        $this->authorizeOrderAccess($request, $order);

        $order = $action->handle($order, $request->input('tags'));

        return ApiResponse::success(['order' => new OrderResource($order)]);
    }

    public function snooze(SnoozeOrderRequest $request, Order $order, SnoozeOrderAction $action): JsonResponse
    {
        $this->authorizeOrderAccess($request, $order);

        $until = $request->input('until') === null ? null : Carbon::parse((string) $request->input('until'));
        $order = $action->handle($order, $until);

        return ApiResponse::success(['order' => new OrderResource($order)]);
    }

    public function fulfill(FulfillOrderRequest $request, Order $order, FulfillOrderAction $action): JsonResponse
    {
        $this->authorizeOrderAccess($request, $order);

        $result = $action->handle(
            $order,
            $request->string('tracking_number')->toString(),
            $request->string('carrier')->toString() ?: null,
        );

        if (! $result->success) {
            return ApiResponse::error($result->message);
        }

        return ApiResponse::success(['order' => new OrderResource($order->fresh())], $result->message);
    }

    public function refund(RefundOrderRequest $request, Order $order, RefundOrderAction $action): JsonResponse
    {
        $this->authorizeOrderAccess($request, $order);

        $result = $action->handle(
            $order,
            $request->has('amount') ? (float) $request->input('amount') : null,
            $request->string('reason')->toString() ?: null,
        );

        if (! $result->success) {
            return ApiResponse::error($result->message);
        }

        return ApiResponse::success(['order' => new OrderResource($order->fresh())], $result->message);
    }

    public function cancel(CancelOrderRequest $request, Order $order, CancelOrderAction $action): JsonResponse
    {
        $this->authorizeOrderAccess($request, $order);

        $result = $action->handle($order, $request->string('reason')->toString() ?: null);

        if (! $result->success) {
            return ApiResponse::error($result->message);
        }

        return ApiResponse::success(['order' => new OrderResource($order->fresh())], $result->message);
    }

    public function packingSlip(Request $request, Order $order, GeneratePackingSlipAction $action): Response
    {
        $this->authorizeOrderAccess($request, $order);

        return $action->handle($order);
    }

    private function authorizeOrderAccess(Request $request, Order $order): void
    {
        /** @var User $user */
        $user = $request->user();

        $member = $user->currentTeamMember();

        if ($order->team_id !== $member?->team_id) {
            abort(404);
        }

        if (! empty($member->store_visibility) && ! in_array($order->connection_id, $member->store_visibility, true)) {
            abort(404);
        }
    }
}
