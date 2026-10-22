<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Http\Requests\UpdateProductStatusRequest;
use App\Http\Resources\ProductPriceHistoryResource;
use App\Http\Resources\ProductResource;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\ProductPriceHistory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class ProductController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $query = Product::query()->orderBy('name');

        if (auth()->user()->role === 'kasir') {
            $query->where('is_active', true);
        }

        return ProductResource::collection($query->get());
    }

    public function store(StoreProductRequest $request): JsonResponse
    {
        $product = DB::transaction(function () use ($request): Product {
            $product = Product::query()->create($request->validated());

            $this->recordAudit('product.created', $product, $request->user()->id, [
                'name' => $product->name,
            ]);

            return $product;
        });

        return (new ProductResource($product))->response()->setStatusCode(201);
    }

    public function update(UpdateProductRequest $request, Product $product): ProductResource
    {
        $validated = $request->validated();
        $pricesChanged = collect(['price_retail', 'price_3kg', 'price_5kg'])
            ->contains(fn (string $field): bool => (int) $product->{$field} !== (int) $validated[$field]);

        $product = DB::transaction(function () use ($request, $product, $validated, $pricesChanged): Product {
            $product->update($validated);

            if ($pricesChanged) {
                ProductPriceHistory::query()->create([
                    'product_id' => $product->id,
                    'price_retail' => $product->price_retail,
                    'price_3kg' => $product->price_3kg,
                    'price_5kg' => $product->price_5kg,
                    'changed_by' => $request->user()->id,
                    'changed_at' => now(),
                ]);

                $this->recordAudit('product.price_changed', $product, $request->user()->id, [
                    'price_retail' => $product->price_retail,
                    'price_3kg' => $product->price_3kg,
                    'price_5kg' => $product->price_5kg,
                ]);
            }

            if ($product->wasChanged('name')) {
                $this->recordAudit('product.updated', $product, $request->user()->id, [
                    'name' => $product->name,
                ]);
            }

            return $product;
        });

        return new ProductResource($product);
    }

    public function updateStatus(UpdateProductStatusRequest $request, Product $product): ProductResource
    {
        $product = DB::transaction(function () use ($request, $product): Product {
            $product->update(['is_active' => $request->boolean('is_active')]);

            $this->recordAudit('product.status_changed', $product, $request->user()->id, [
                'is_active' => $product->is_active,
            ]);

            return $product;
        });

        return new ProductResource($product);
    }

    public function priceHistory(Product $product): AnonymousResourceCollection
    {
        return ProductPriceHistoryResource::collection(
            $product->priceHistories()->latest('changed_at')->get()
        );
    }

    private function recordAudit(string $action, Product $product, int $userId, array $payload): void
    {
        AuditLog::query()->create([
            'user_id' => $userId,
            'action' => $action,
            'entity_type' => Product::class,
            'entity_id' => $product->id,
            'payload' => $payload,
        ]);
    }
}
