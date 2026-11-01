<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreStockCountRequest;
use App\Http\Requests\CancelStockCountRequest;
use App\Http\Requests\StockCountIndexRequest;
use App\Http\Resources\StockCountResource;
use App\Services\StockCountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class StockCountController extends Controller
{
    public function index(StockCountIndexRequest $request, StockCountService $stockCountService): AnonymousResourceCollection
    {
        return StockCountResource::collection($stockCountService->list($request->validated('status')));
    }

    public function store(
        StoreStockCountRequest $request,
        StockCountService $stockCountService
    ): JsonResponse {
        $count = $stockCountService->create($request->validated(), $request->user()->id);

        return (new StockCountResource($count))->response()->setStatusCode(201);
    }

    public function destroy(
        CancelStockCountRequest $request,
        \App\Models\StockCount $stockCount,
        StockCountService $stockCountService
    ): StockCountResource {
        try {
            $count = $stockCountService->cancel(
                $stockCount,
                (string) $request->validated('reason'),
                $request->user()->id
            );
        } catch (\LogicException $exception) {
            abort(response()->json(['message' => $exception->getMessage()], 409));
        }

        return new StockCountResource($count);
    }
}
