<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreStockCountRequest;
use App\Http\Resources\StockCountResource;
use App\Services\StockCountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class StockCountController extends Controller
{
    public function index(StockCountService $stockCountService): AnonymousResourceCollection
    {
        return StockCountResource::collection($stockCountService->list());
    }

    public function store(
        StoreStockCountRequest $request,
        StockCountService $stockCountService
    ): JsonResponse {
        $count = $stockCountService->create($request->validated(), $request->user()->id);

        return (new StockCountResource($count))->response()->setStatusCode(201);
    }
}
