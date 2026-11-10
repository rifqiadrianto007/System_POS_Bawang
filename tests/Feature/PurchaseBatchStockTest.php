<?php

use App\Models\Product;
use App\Models\Purchase;
use App\Models\StockCount;
use App\Models\StockLot;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function batchStockOwner(): array
{
    $owner = User::factory()->create(['role' => 'owner']);

    return [$owner, $owner->createToken('batch-stock')->plainTextToken];
}

function batchStockProduct(string $name, string $jenis): Product
{
    return Product::query()->create(['name' => $name, 'jenis' => $jenis, 'price_retail' => 27000, 'price_5kg' => 23000]);
}

function batchStockPurchasePayload(int $supplierId): array
{
    return ['supplier_id' => $supplierId, 'purchased_at' => now('Asia/Jakarta')->toDateString(), 'jenis' => 'merah',
        'gross_weight_kg' => '100.000', 'price_per_kg' => 21000, 'notes' => null];
}

it('records purchase weight as warehouse stock without draft or finalization state', function () {
    [$owner, $token] = batchStockOwner();
    $supplier = Supplier::query()->create(['name' => 'Pemasok Batch']);

    $purchase = $this->withToken($token)->postJson('/api/v1/purchases', batchStockPurchasePayload($supplier->id))
        ->assertCreated()->assertJsonPath('data.gross_weight_kg', '100.000')
        ->assertJsonPath('data.sorted_weight_kg', '0.000')->assertJsonPath('data.remaining_weight_kg', '100.000')
        ->assertJsonPath('data.sort_percentage', 0)->assertJsonPath('data.status_sortir', 'belum_disortir')
        ->assertJsonPath('data.lots', [])->assertJsonMissingPath('data.status')->json('data');

    $this->withToken($token)->getJson('/api/v1/stock')->assertOk()
        ->assertJsonPath('totals.warehouse_kg', '100.000')->assertJsonPath('totals.display_kg', '0.000')
        ->assertJsonPath('totals.inventory_value', 0);

    $this->withToken($token)->getJson("/api/v1/purchases/{$purchase['id']}")
        ->assertOk()->assertJsonMissingPath('data.closed_at');
});

it('links batch recounts to display lots and reports 55 kg sorted from 100 kg', function () {
    [, $token] = batchStockOwner();
    $supplier = Supplier::query()->create(['name' => 'Pemasok Contoh']);
    $redA = batchStockProduct('Merah Grade A', 'merah');
    $redB = batchStockProduct('Merah Grade B', 'merah');
    $purchase = $this->withToken($token)->postJson('/api/v1/purchases', batchStockPurchasePayload($supplier->id))
        ->assertCreated()->json('data');

    foreach ([[$redA, '30.000'], [$redB, '25.000']] as [$product, $weight]) {
        $this->withToken($token)->postJson('/api/v1/stock-counts', [
            'purchase_id' => $purchase['id'], 'product_id' => $product->id,
            'counted_weight_kg' => $weight, 'counted_at' => now('Asia/Jakarta')->toIso8601String(),
        ])->assertCreated()->assertJsonPath('data.purchase_id', $purchase['id']);
    }

    $detail = $this->withToken($token)->getJson('/api/v1/purchases/'.$purchase['id'])->assertOk()
        ->assertJsonPath('data.gross_weight_kg', '100.000')->assertJsonPath('data.sorted_weight_kg', '55.000')
        ->assertJsonPath('data.remaining_weight_kg', '45.000')->assertJsonPath('data.sort_percentage', 55)
        ->assertJsonPath('data.status_sortir', 'tersortir')->assertJsonCount(2, 'data.lots')
        ->assertJsonPath('data.lots.0.purchase_id', $purchase['id'])->json('data');

    $this->withToken($token)->getJson('/api/v1/stock')->assertOk()
        ->assertJsonPath('totals.display_kg', '55.000')->assertJsonPath('totals.warehouse_kg', '45.000')
        ->assertJsonPath('data.0.stock_kg', '30.000')->assertJsonPath('data.1.stock_kg', '25.000');

    expect($detail['lots'])->toBeArray()
        ->and($detail['lots'][0])->toHaveKeys(['purchase_id', 'product_id', 'clean_weight_kg'])
        ->and(StockCount::query()->count())->toBe(2)
        ->and(StockLot::query()->where('purchase_id', $purchase['id'])->count())->toBe(2);
});

it('removes the old close and finalize purchase routes and rejects a recount for another kind', function () {
    [, $token] = batchStockOwner();
    $supplier = Supplier::query()->create(['name' => 'Pemasok Contoh']);
    $white = batchStockProduct('Putih Grade A', 'putih');
    $purchase = $this->withToken($token)->postJson('/api/v1/purchases', batchStockPurchasePayload($supplier->id))
        ->assertCreated()->json('data');

    $this->withToken($token)->postJson('/api/v1/stock-counts', [
        'product_id' => $white->id, 'counted_weight_kg' => '5.000', 'counted_at' => now('Asia/Jakarta')->toIso8601String(),
    ])->assertUnprocessable()->assertJsonValidationErrors(['purchase_id']);

    $this->withToken($token)->postJson('/api/v1/purchases/'.$purchase['id'].'/close')->assertNotFound();
    $this->withToken($token)->postJson('/api/v1/purchases/'.$purchase['id'].'/finalize')->assertNotFound();
    $this->withToken($token)->postJson('/api/v1/stock-counts', [
        'purchase_id' => $purchase['id'], 'product_id' => $white->id, 'counted_weight_kg' => '5.000',
        'counted_at' => now('Asia/Jakarta')->toIso8601String(),
    ])->assertUnprocessable()->assertJsonValidationErrors(['product_id']);
});

it('updates the batch lot weight on recount and restores its prior weight on cancellation', function () {
    [, $token] = batchStockOwner();
    $supplier = Supplier::query()->create(['name' => 'Pemasok Recount']);
    $product = batchStockProduct('Merah Recount', 'merah');
    $purchase = $this->withToken($token)->postJson('/api/v1/purchases', batchStockPurchasePayload($supplier->id))
        ->assertCreated()->json('data');
    $headers = ['Authorization' => 'Bearer '.$token];

    $this->withHeaders($headers)->postJson('/api/v1/stock-counts', [
        'purchase_id' => $purchase['id'], 'product_id' => $product->id, 'counted_weight_kg' => '30.000',
        'counted_at' => now('Asia/Jakarta')->toIso8601String(),
    ])->assertCreated();
    $latestCount = $this->withHeaders($headers)->postJson('/api/v1/stock-counts', [
        'purchase_id' => $purchase['id'], 'product_id' => $product->id, 'counted_weight_kg' => '28.000',
        'counted_at' => now('Asia/Jakarta')->addMinute()->toIso8601String(),
    ])->assertCreated()->json('data');

    $this->withHeaders($headers)->getJson('/api/v1/purchases/'.$purchase['id'])->assertOk()
        ->assertJsonPath('data.sorted_weight_kg', '28.000')->assertJsonPath('data.remaining_weight_kg', '72.000')
        ->assertJsonPath('data.lots.0.clean_weight_kg', '28.000');

    $this->withHeaders($headers)->deleteJson('/api/v1/stock-counts/'.$latestCount['id'], ['reason' => 'Koreksi hitung ulang'])->assertOk();
    $this->withHeaders($headers)->getJson('/api/v1/purchases/'.$purchase['id'])->assertOk()
        ->assertJsonPath('data.sorted_weight_kg', '30.000')->assertJsonPath('data.remaining_weight_kg', '70.000')
        ->assertJsonPath('data.lots.0.clean_weight_kg', '30.000');
});
