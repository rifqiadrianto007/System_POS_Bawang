<?php

namespace App\Http\Controllers;

use App\Http\Resources\StockSummaryResource;
use App\Services\StockService;
use Illuminate\Http\JsonResponse;

class StockController extends Controller
{
    public function __invoke(StockService $stockService): JsonResponse
    {
        $result = $stockService->summaries();

        return response()->json([
            'data' => StockSummaryResource::collection($result['products'])->resolve(request()),
            'totals' => $result['totals'],
        ]);
    }
}
