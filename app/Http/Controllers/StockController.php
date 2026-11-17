<?php

namespace App\Http\Controllers;

use App\Http\Resources\StockSummaryResource;
use App\Http\Resources\ProductBatchResource;
use App\Http\Requests\StockSummaryRequest;
use App\Models\Product;
use App\Services\StockService;
use Illuminate\Http\JsonResponse;

class StockController extends Controller
{
    public function __invoke(StockSummaryRequest $request, StockService $stockService): JsonResponse
    {
        $from = $request->validated('from') ?? now('Asia/Jakarta')->startOfMonth()->toDateString();
        $to = $request->validated('to') ?? now('Asia/Jakarta')->toDateString();
        $result = $stockService->summaries($from, $to);

        return response()->json([
            'data' => StockSummaryResource::collection($result['products'])->resolve(request()),
            'totals' => $result['totals'],
        ]);
    }

    public function productBatches(Product $product, StockService $stockService): JsonResponse
    {
        return response()->json(['data' => ProductBatchResource::collection($stockService->batchesForProduct($product))->resolve(request())]);
    }
}
