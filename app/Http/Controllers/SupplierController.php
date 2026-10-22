<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSupplierRequest;
use App\Http\Requests\UpdateSupplierRequest;
use App\Http\Resources\SupplierResource;
use App\Models\AuditLog;
use App\Models\Supplier;
use App\Services\PurchaseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class SupplierController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return SupplierResource::collection(Supplier::query()->orderBy('name')->get());
    }

    public function store(StoreSupplierRequest $request): JsonResponse
    {
        $supplier = DB::transaction(function () use ($request): Supplier {
            $supplier = Supplier::query()->create($request->validated());
            AuditLog::query()->create([
                'user_id' => $request->user()->id,
                'action' => 'supplier.created',
                'entity_type' => Supplier::class,
                'entity_id' => $supplier->id,
                'payload' => ['name' => $supplier->name],
            ]);

            return $supplier;
        });

        return (new SupplierResource($supplier))->response()->setStatusCode(201);
    }

    public function update(
        UpdateSupplierRequest $request,
        Supplier $supplier,
        PurchaseService $purchaseService
    ): SupplierResource {
        return new SupplierResource(
            $purchaseService->updateSupplier($supplier, $request->validated(), $request->user()->id)
        );
    }
}
