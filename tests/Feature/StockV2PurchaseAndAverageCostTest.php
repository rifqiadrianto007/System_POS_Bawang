<?php

use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\AverageCostService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function v2User(): array
{
    $user = User::factory()->create(['role' => 'owner', 'username' => 'owner-v2-test']);

    return [$user, $user->createToken('v2-test')->plainTextToken];
}

function v2Product(string $name = 'Merah V2'): Product
{
    return Product::query()->create(['name' => $name, 'jenis' => 'merah', 'price_retail' => 27500, 'price_5kg' => 24000, 'is_active' => true]);
}

it('AV-01 calculates weighted average inventory value and cogs from purchase and ledger values', function () {
    [, $token] = v2User();
    $product = v2Product();
    $supplier = \App\Models\Supplier::query()->create(['name' => 'Supplier AV']);
    StockMovement::query()->create(['product_id' => $product->id, 'type' => 'in_sort', 'weight_kg' => '10.000', 'value_delta' => 200000, 'occurred_at' => now()]);
    $purchase = $this->withToken($token)->postJson('/api/v1/purchases', [
        'supplier_id' => $supplier->id, 'purchased_at' => now('Asia/Jakarta')->toDateString(), 'jenis' => 'merah',
        'gross_weight_kg' => '30.000', 'price_per_kg' => 20500,
    ])->assertCreated()->json('data');
    $this->withToken($token)->postJson('/api/v1/stock-counts', [
        'purchase_id' => $purchase['id'], 'product_id' => $product->id, 'counted_weight_kg' => '30.000',
        'counted_at' => now('Asia/Jakarta')->toIso8601String(),
    ])->assertCreated();
    $sale = $this->withToken($token)->postJson('/api/v1/transactions', [
        'client_uuid' => '30000000-0000-4000-8000-000000000002', 'customer_category' => 'ecer',
        'items' => [['product_id' => $product->id, 'weight_kg' => '5.000']], 'payment_method' => 'cash', 'final_total' => 120000, 'amount_paid' => 120000,
    ])->assertCreated();
    expect($sale->json('data.items.0.cogs'))->toBe(101875)
        ->and(app(AverageCostService::class)->stock($product)['stock_kg'])->toBe('35.000')
        ->and(app(AverageCostService::class)->stock($product)['stock_value'])->toBe(713125)
        ->and(app(AverageCostService::class)->stock($product)['avg_cost'])->toBe(20375);
});

it('AV-02 depleting all positive stock uses the entire remaining stock value', function () {
    [, $token] = v2User();
    $product = v2Product();
    StockMovement::query()->create(['product_id' => $product->id, 'type' => 'in_sort', 'weight_kg' => '10.000', 'value_delta' => 200001, 'occurred_at' => now()]);
    $sale = $this->withToken($token)->postJson('/api/v1/transactions', [
        'client_uuid' => '30000000-0000-4000-8000-000000000003', 'customer_category' => 'grosir',
        'items' => [['product_id' => $product->id, 'weight_kg' => '10.000']], 'payment_method' => 'cash', 'final_total' => 240000, 'amount_paid' => 240000,
    ])->assertCreated();
    expect($sale->json('data.items.0.cogs'))->toBe(200001)->and(app(AverageCostService::class)->stock($product)['stock_value'])->toBe(0);
});

it('AV-06 rounds average cogs half up once per transaction item', function () {
    [, $token] = v2User();
    $product = v2Product();
    StockMovement::query()->create(['product_id' => $product->id, 'type' => 'in_sort', 'weight_kg' => '3.000', 'value_delta' => 61000, 'occurred_at' => now()]);
    $sale = $this->withToken($token)->postJson('/api/v1/transactions', [
        'client_uuid' => '30000000-0000-4000-8000-000000000006', 'customer_category' => 'ecer',
        'items' => [['product_id' => $product->id, 'weight_kg' => '1.000']], 'payment_method' => 'cash', 'final_total' => 27500, 'amount_paid' => 27500,
    ])->assertCreated();
    expect($sale->json('data.items.0.cogs'))->toBe(20333)->and(app(AverageCostService::class)->stock($product)['stock_value'])->toBe(40667);
});
