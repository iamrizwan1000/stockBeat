<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Products\BulkUpdateCostPricesAction;
use App\Actions\Products\UpdateCostPriceAction;
use App\Actions\Products\UpdateProductStockAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Products\BulkUpdateCostPricesRequest;
use App\Http\Requests\Products\UpdateCostPriceRequest;
use App\Http\Requests\Products\UpdateProductStockRequest;
use App\Http\Resources\ProductResource;
use App\Http\Responses\ApiResponse;
use App\Models\Product;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * @group Products
 *
 * Polled product/stock snapshots (Plan §4.4's `low_stock` trigger) — not a
 * catalog management feature. Seller-editable fields are `cost_price` (Plan
 * §4.12 Phase B, since no platform API exposes true cost-of-goods) and, as
 * of §4.13, `stock_quantity` on Shopify/WooCommerce connections — a quick
 * fix, not full listing/catalog editing.
 */
class ProductController extends Controller
{
    /**
     * List this team's polled products.
     */
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $team = $user->currentTeam();

        $products = $team === null
            ? collect()
            : Product::query()->where('team_id', $team->id)->orderBy('title')->get();

        return ApiResponse::success(['products' => ProductResource::collection($products)]);
    }

    /**
     * Set (or clear) a product's seller-entered cost price.
     */
    public function updateCostPrice(UpdateCostPriceRequest $request, Product $product, UpdateCostPriceAction $action): JsonResponse
    {
        $this->authorizeProductAccess($request, $product);

        $rawCostPrice = $request->validated('cost_price');
        $costPrice = $rawCostPrice === null ? null : (float) $rawCostPrice;

        $product = $action->handle($product, $costPrice);

        return ApiResponse::success(['product' => new ProductResource($product)]);
    }

    /**
     * Set (or clear) cost prices on several products in one call.
     */
    public function bulkUpdateCostPrices(BulkUpdateCostPricesRequest $request, BulkUpdateCostPricesAction $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $team = $user->currentTeam();

        if ($team === null) {
            throw ValidationException::withMessages([
                'updates' => ['Complete profile setup first.'],
            ]);
        }

        $products = $action->handle($team, $request->validated('updates'));

        return ApiResponse::success(['products' => ProductResource::collection($products->values())]);
    }

    /**
     * Push a corrected stock quantity to the product's platform.
     *
     * Calls through to the real channel adapter where available (Shopify/WooCommerce today); capability-checked
     * server-side, so this 422s with a plain-language message on platforms that aren't supported yet.
     *
     * @response 200 scenario="success" {
     *   "success": true,
     *   "message": "Stock quantity updated.",
     *   "data": { "product": { "id": 1, "connection_id": 1, "sku": "SKU-1234", "title": "Blue Widget", "stock_quantity": 25, "cost_price": null } }
     * }
     * @response 422 scenario="capability not supported" {
     *   "success": false,
     *   "message": "The given data was invalid.",
     *   "errors": { "product": ["This channel doesn't support updating stock from here."] }
     * }
     */
    public function updateStock(UpdateProductStockRequest $request, Product $product, UpdateProductStockAction $action): JsonResponse
    {
        $this->authorizeProductAccess($request, $product);

        $result = $action->handle($product, (int) $request->validated('quantity'));

        if (! $result->success) {
            return ApiResponse::error($result->message);
        }

        return ApiResponse::success(['product' => new ProductResource($product->fresh())], $result->message);
    }

    private function authorizeProductAccess(Request $request, Product $product): void
    {
        /** @var User $user */
        $user = $request->user();

        if ($product->team_id !== $user->currentTeam()?->id) {
            abort(404);
        }
    }
}
