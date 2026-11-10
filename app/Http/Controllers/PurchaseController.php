<?php

namespace App\Http\Controllers;

use App\Http\Requests\PurchaseIndexRequest;
use App\Http\Requests\StorePurchaseRequest;
use App\Http\Requests\UpdatePurchaseRequest;
use App\Http\Resources\PurchaseListResource;
use App\Http\Resources\PurchaseResource;
use App\Models\Purchase;
use App\Services\PurchaseService;
use Illuminate\Http\JsonResponse;
use LogicException;

class PurchaseController extends Controller
{
    public function index(PurchaseIndexRequest $request, PurchaseService $purchaseService): JsonResponse
    {
        $result = $purchaseService->list($request->validated());
        $purchases = $result['purchases'];

        return response()->json([
            'data' => PurchaseListResource::collection($purchases)->resolve($request),
            'meta' => [
                'total' => $purchases->total(),
                'current_page' => $purchases->currentPage(),
                'last_page' => $purchases->lastPage(),
                'per_page' => $purchases->perPage(),
            ],
            'summary' => $result['summary'],
        ]);
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

}
