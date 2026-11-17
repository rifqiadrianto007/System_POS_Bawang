<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreStockAdditionRequest;
use App\Http\Resources\StockAdditionResource;
use App\Services\StockAdditionService;
use Illuminate\Http\JsonResponse;
use LogicException;

class StockAdditionController extends Controller
{
    public function store(StoreStockAdditionRequest $request, StockAdditionService $service): JsonResponse
    {
        try {
            return (new StockAdditionResource($service->create($request->validated(), $request->user()->id)))
                ->response()->setStatusCode(201);
        } catch (LogicException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }
    }
}
