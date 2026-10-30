<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePurchaseRequest;
use App\Http\Requests\StoreStockLotRequest;
use App\Http\Requests\UpdatePurchaseRequest;
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
    public function index(PurchaseService $purchaseService): AnonymousResourceCollection
    {
        return PurchaseResource::collection($purchaseService->list());
    }

    public function store(StorePurchaseRequest $request, PurchaseService $purchaseService): JsonResponse
    {
        $purchase = $purchaseService->create($request->validated(), $request->user()->id);

        return (new PurchaseResource($purchase))->response()->setStatusCode(201);
    }

    public function show(Purchase $purchase, PurchaseService $purchaseService): PurchaseResource
    {
        return new PurchaseResource($purchaseService->present($purchase));
    }

    public function update(
        UpdatePurchaseRequest $request,
        Purchase $purchase,
        PurchaseService $purchaseService
    ): PurchaseResource|JsonResponse {
        try {
            return new PurchaseResource($purchaseService->update($purchase, $request->validated(), $request->user()->id));
        } catch (LogicException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }
    }

    public function destroy(Purchase $purchase, PurchaseService $purchaseService): JsonResponse
    {
        try {
            $purchaseService->delete($purchase, request()->user()->id);
        } catch (LogicException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return response()->json(['message' => 'Kulak dihapus.']);
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

    public function updateLot(
        StoreStockLotRequest $request,
        Purchase $purchase,
        StockLot $lot,
        PurchaseService $purchaseService
    ): StockLotResource|JsonResponse {
        try {
            $lot = $purchaseService->updateLot($purchase, $lot, $request->validated(), $request->user()->id);
        } catch (LogicException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return new StockLotResource($lot);
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
