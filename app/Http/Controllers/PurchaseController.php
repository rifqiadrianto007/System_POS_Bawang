<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePurchaseRequest;
use App\Http\Requests\StoreStockLotRequest;
use App\Http\Resources\PurchaseResource;
use App\Http\Resources\StockLotResource;
use App\Models\Purchase;
use App\Models\StockLot;
use App\Services\PurchaseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use LogicException;

class PurchaseController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return PurchaseResource::collection(
            Purchase::query()->with('supplier')->latest('purchased_at')->get()
        );
    }

    public function store(StorePurchaseRequest $request, PurchaseService $purchaseService): JsonResponse
    {
        $purchase = $purchaseService->create($request->validated(), $request->user()->id);

        return (new PurchaseResource($purchase))->response()->setStatusCode(201);
    }

    public function show(Purchase $purchase): PurchaseResource
    {
        return new PurchaseResource($purchase->load(['supplier', 'stockLots']));
    }

    public function addLot(
        StoreStockLotRequest $request,
        Purchase $purchase,
        PurchaseService $purchaseService
    ): JsonResponse {
        try {
            $lot = $purchaseService->addLot($purchase, $request->validated(), $request->user()->id);
        } catch (LogicException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return (new StockLotResource($lot))->response()->setStatusCode(201);
    }

    public function deleteLot(
        Purchase $purchase,
        StockLot $lot,
        PurchaseService $purchaseService
    ): JsonResponse {
        try {
            $purchaseService->deleteLot($purchase, $lot, request()->user()->id);
        } catch (LogicException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return response()->json(null, 204);
    }

    public function finalize(Purchase $purchase, PurchaseService $purchaseService): PurchaseResource
    {
        try {
            $purchase = $purchaseService->finalize($purchase, request()->user()->id);
        } catch (LogicException $exception) {
            abort(response()->json(['message' => $exception->getMessage()], 409));
        }

        return new PurchaseResource($purchase);
    }
}
