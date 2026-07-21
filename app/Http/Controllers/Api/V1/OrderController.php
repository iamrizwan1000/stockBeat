<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Inbox\GetOrCreateInboxThreadAction;
use App\Actions\Inbox\RenderReplyTemplateAction;
use App\Actions\Inbox\SendInboxMessageAction;
use App\Actions\Orders\AddOrderNoteAction;
use App\Actions\Orders\CancelOrderAction;
use App\Actions\Orders\FulfillOrderAction;
use App\Actions\Orders\GeneratePackingSlipAction;
use App\Actions\Orders\ListOrdersAction;
use App\Actions\Orders\RefundOrderAction;
use App\Actions\Orders\SnoozeOrderAction;
use App\Actions\Orders\UpdateOrderTagsAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inbox\SendInboxMessageRequest;
use App\Http\Requests\Orders\AddOrderNoteRequest;
use App\Http\Requests\Orders\CancelOrderRequest;
use App\Http\Requests\Orders\FulfillOrderRequest;
use App\Http\Requests\Orders\ListOrdersRequest;
use App\Http\Requests\Orders\RefundOrderRequest;
use App\Http\Requests\Orders\SnoozeOrderRequest;
use App\Http\Requests\Orders\UpdateOrderTagsRequest;
use App\Http\Resources\InboxMessageResource;
use App\Http\Resources\OrderNoteResource;
use App\Http\Resources\OrderResource;
use App\Http\Responses\ApiResponse;
use App\Models\Order;
use App\Models\ReplyTemplate;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;

/**
 * @group Orders
 *
 * The unified order feed across all connected channels, plus quick actions
 * (fulfill/tracking, refund, cancel, notes/tags, packing slip).
 */
class OrderController extends Controller
{
    /**
     * List orders.
     *
     * Cursor-paginated, filterable by channel/store/status/date/value/tag, with global search
     * across order number, customer name/email, and item SKU/title. `history_days` is plan-gated
     * server-side; test orders are excluded unless the team is viewing in test mode.
     *
     * @response 200 scenario="success" {
     *   "success": true,
     *   "message": null,
     *   "data": {
     *     "orders": [
     *       {
     *         "id": 1,
     *         "platform": "woo",
     *         "connection_id": 1,
     *         "order_number": "#1042",
     *         "status": "unfulfilled",
     *         "fulfillment_status": "unfulfilled",
     *         "payment_status": "paid",
     *         "currency": "AUD",
     *         "total": "84.00",
     *         "total_base_currency": "84.00",
     *         "customer_name": "Alex Chen",
     *         "customer_email": "alex@example.com",
     *         "shipping_address": { "line1": "1 Example St", "city": "Sydney", "postcode": "2000", "country": "AU" },
     *         "placed_at": "2026-07-16T00:30:00.000000Z",
     *         "ship_by_at": "2026-07-18T00:30:00.000000Z",
     *         "ship_by_hours_remaining": 46.5,
     *         "is_ship_by_urgent": false,
     *         "tags": ["gift"],
     *         "is_test": false,
     *         "snoozed_until": null
     *       }
     *     ],
     *     "next_cursor": "eyJpZCI6MSwiX3BvaW50c1RvTmV4dEl0ZW1zIjp0cnVlfQ"
     *   }
     * }
     */
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

    /**
     * Get an order.
     *
     * Includes items and notes. 404s if the order isn't in the caller's team, or outside the
     * caller's `store_visibility` restriction.
     *
     * @response 200 scenario="success" {
     *   "success": true,
     *   "message": null,
     *   "data": {
     *     "order": {
     *       "id": 1,
     *       "platform": "woo",
     *       "connection_id": 1,
     *       "order_number": "#1042",
     *       "status": "unfulfilled",
     *       "fulfillment_status": "unfulfilled",
     *       "payment_status": "paid",
     *       "currency": "AUD",
     *       "total": "84.00",
     *       "total_base_currency": "84.00",
     *       "customer_name": "Alex Chen",
     *       "customer_email": "alex@example.com",
     *       "shipping_address": { "line1": "1 Example St", "city": "Sydney", "postcode": "2000", "country": "AU" },
     *       "placed_at": "2026-07-16T00:30:00.000000Z",
     *       "ship_by_at": "2026-07-18T00:30:00.000000Z",
     *       "ship_by_hours_remaining": 46.5,
     *       "is_ship_by_urgent": false,
     *       "tags": ["gift"],
     *       "is_test": false,
     *       "snoozed_until": null,
     *       "items": [
     *         { "id": 1, "sku": "VNT-014", "title": "Vintage Denim Jacket", "image_url": "https://example.com/img/vnt-014.jpg", "qty": 1, "price": "84.00" }
     *       ],
     *       "notes": []
     *     }
     *   }
     * }
     */
    public function show(Request $request, Order $order): JsonResponse
    {
        $this->authorizeOrderAccess($request, $order);

        $order->load(['items', 'notes']);

        return ApiResponse::success(['order' => new OrderResource($order)]);
    }

    /**
     * Add a note to an order.
     *
     * @response 201 scenario="success" {
     *   "success": true,
     *   "message": null,
     *   "data": {
     *     "note": { "id": 1, "body": "Customer asked to hold for pickup.", "user_id": 1, "created_at": "2026-07-16T02:00:00.000000Z" }
     *   }
     * }
     */
    public function addNote(AddOrderNoteRequest $request, Order $order, AddOrderNoteAction $action): JsonResponse
    {
        $this->authorizeOrderAccess($request, $order);

        /** @var User $user */
        $user = $request->user();

        $note = $action->handle($order, $user, $request->string('body')->toString());

        return ApiResponse::success(['note' => new OrderNoteResource($note)], status: 201);
    }

    /**
     * Replace an order's tags.
     *
     * @response 200 scenario="success" {
     *   "success": true,
     *   "message": null,
     *   "data": { "order": { "id": 1, "order_number": "#1042", "tags": ["gift", "priority"] } }
     * }
     */
    public function updateTags(UpdateOrderTagsRequest $request, Order $order, UpdateOrderTagsAction $action): JsonResponse
    {
        $this->authorizeOrderAccess($request, $order);

        $order = $action->handle($order, $request->input('tags'));

        return ApiResponse::success(['order' => new OrderResource($order)]);
    }

    /**
     * Snooze or unsnooze an order.
     *
     * Pass `until` as an ISO-8601 datetime to snooze, or omit/null it to clear the snooze.
     *
     * @response 200 scenario="success" {
     *   "success": true,
     *   "message": null,
     *   "data": { "order": { "id": 1, "order_number": "#1042", "snoozed_until": "2026-07-18T00:00:00.000000Z" } }
     * }
     */
    public function snooze(SnoozeOrderRequest $request, Order $order, SnoozeOrderAction $action): JsonResponse
    {
        $this->authorizeOrderAccess($request, $order);

        $until = $request->input('until') === null ? null : Carbon::parse((string) $request->input('until'));
        $order = $action->handle($order, $until);

        return ApiResponse::success(['order' => new OrderResource($order)]);
    }

    /**
     * Fulfill an order with tracking info.
     *
     * Calls through to the real channel adapter where available (WooCommerce today); capability-checked
     * server-side, so this 422s with a plain-language message on platforms/actions that aren't supported yet.
     *
     * @response 200 scenario="success" {
     *   "success": true,
     *   "message": "Order marked as fulfilled.",
     *   "data": { "order": { "id": 1, "order_number": "#1042", "status": "shipped", "fulfillment_status": "fulfilled" } }
     * }
     * @response 422 scenario="capability not supported" {
     *   "success": false,
     *   "message": "The given data was invalid.",
     *   "errors": { "order": ["This channel doesn't support marking orders fulfilled from here."] }
     * }
     */
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

    /**
     * Refund an order.
     *
     * `amount` defaults to a full refund when omitted. Calls through to the real channel adapter
     * where available (WooCommerce today).
     *
     * @response 200 scenario="success" {
     *   "success": true,
     *   "message": "Order refunded.",
     *   "data": { "order": { "id": 1, "order_number": "#1042", "status": "refunded", "payment_status": "refunded" } }
     * }
     */
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

    /**
     * Cancel an order.
     *
     * Calls through to the real channel adapter where available (WooCommerce today).
     *
     * @response 200 scenario="success" {
     *   "success": true,
     *   "message": "Order cancelled.",
     *   "data": { "order": { "id": 1, "order_number": "#1042", "status": "cancelled" } }
     * }
     */
    public function cancel(CancelOrderRequest $request, Order $order, CancelOrderAction $action): JsonResponse
    {
        $this->authorizeOrderAccess($request, $order);

        $result = $action->handle($order, $request->string('reason')->toString() ?: null);

        if (! $result->success) {
            return ApiResponse::error($result->message);
        }

        return ApiResponse::success(['order' => new OrderResource($order->fresh())], $result->message);
    }

    /**
     * Get a packing slip PDF.
     *
     * Returns the rendered PDF directly (`Content-Type: application/pdf`), not a JSON envelope.
     */
    public function packingSlip(Request $request, Order $order, GeneratePackingSlipAction $action): Response
    {
        $this->authorizeOrderAccess($request, $order);

        return $action->handle($order);
    }

    /**
     * Message the customer (Plan §4.3: "opens inbox thread").
     *
     * Gets or creates the order's unified-inbox thread (Plan §4.5, Shopify/Woo
     * order-linked email threading) and sends the first (or next) message —
     * a repeat call to the same order always continues the same thread.
     *
     * @response 201 scenario="success" {
     *   "success": true,
     *   "message": null,
     *   "data": {
     *     "message": { "id": 1, "direction": "out", "body": "Hi! Just checking in on your order.", "status": "sent", "created_at": "2026-07-17T02:00:00.000000Z" }
     *   }
     * }
     */
    public function message(
        SendInboxMessageRequest $request,
        Order $order,
        GetOrCreateInboxThreadAction $getOrCreateThread,
        SendInboxMessageAction $sendMessage,
        RenderReplyTemplateAction $renderTemplate,
    ): JsonResponse {
        $this->authorizeOrderAccess($request, $order);

        /** @var User $user */
        $user = $request->user();

        $thread = $getOrCreateThread->handle($order);

        $body = $request->filled('reply_template_id')
            ? $renderTemplate->handle(ReplyTemplate::query()->findOrFail($request->integer('reply_template_id'))->body_with_variables, $thread)
            : $request->string('body')->toString();

        $message = $sendMessage->handle($user, $thread, $body);

        return ApiResponse::success(['message' => new InboxMessageResource($message)], status: 201);
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
