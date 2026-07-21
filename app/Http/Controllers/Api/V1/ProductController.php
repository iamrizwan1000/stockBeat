<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Products\UpdateCostPriceAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Products\UpdateCostPriceRequest;
use App\Http\Resources\ProductResource;
use App\Http\Responses\ApiResponse;
use App\Models\Product;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Products
 *
 * Polled product/stock snapshots (Plan §4.4's `low_stock` trigger) — not a
 * catalog management feature. The only seller-editable field is
 * `cost_price` (Plan §4.12 Phase B), since no platform API exposes true
 * cost-of-goods.
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

    private function authorizeProductAccess(Request $request, Product $product): void
    {
        /** @var User $user */
        $user = $request->user();

        if ($product->team_id !== $user->currentTeam()?->id) {
            abort(404);
        }
    }
}
