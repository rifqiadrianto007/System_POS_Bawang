<?php

use App\Models\Product;
use App\Models\Purchase;
use App\Models\StockCount;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function countUser(string $role, string $suffix): array
{
    $user = User::factory()->create([
        'role' => $role,
        'username' => $role.'-count-'.$suffix,
    ]);

    return [$user, $user->createToken('stock-count-test')->plainTextToken];
}

function countProduct(): Product
{
    return Product::query()->create([
        'name' => 'Merah A',
        'price_retail' => 27500,
        'price_3kg' => 26000,
        'price_5kg' => 24000,
    ]);
}

function countLot(Product $product, int $unitCost, string $date): StockLot
{
    $supplier = Supplier::query()->create(['name' => 'Supplier '.$date]);
    $owner = User::factory()->create(['role' => 'owner']);
    $purchase = Purchase::query()->create([
        'supplier_id' => $supplier->id,
        'purchased_at' => $date,
        'gross_weight_kg' => '20.000',
        'price_per_kg' => $unitCost,
        'total_cost' => $unitCost,
        'status' => 'finalized',
        'created_by' => $owner->id,
    ]);

    return StockLot::query()->create([
        'purchase_id' => $purchase->id,
        'product_id' => $product->id,
        'clean_weight_kg' => '20.000',
        'unit_cost' => $unitCost,
        'cleaned_at' => $date,
    ]);
}

function countMovement(Product $product, StockLot $lot, string $weight): void
{
    StockMovement::query()->create([
        'product_id' => $product->id,
        'lot_id' => $lot->id,
        'type' => 'in_clean',
        'weight_kg' => $weight,
        'unit_cost' => $lot->unit_cost,
        'occurred_at' => '2026-10-22 01:00:00',
    ]);
}

it('records a display count and calculates the documented 1.5 kg shrink loss', function () {
    [$owner, $token] = countUser('owner', 'shrink');
    $product = countProduct();
    $lot = countLot($product, 21250, '2026-10-22');
    countMovement($product, $lot, '20.000');

    $response = $this->withToken($token)
        ->postJson('/api/v1/stock-counts', [
            'product_id' => $product->id,
            'counted_weight_kg' => '18.500',
            'counted_at' => '2026-10-22T10:00:00+07:00',
            'notes' => 'Hitung display pagi',
        ])
        ->assertCreated()
        ->assertJsonPath('data.product_id', $product->id)
        ->assertJsonPath('data.system_weight_kg', '20.000')
        ->assertJsonPath('data.counted_weight_kg', '18.500')
        ->assertJsonPath('data.diff_kg', '-1.500')
        ->assertJsonPath('data.unit_cost_avg', 21250)
        ->assertJsonPath('data.loss_value', 31875);

    $count = StockCount::query()->findOrFail($response->json('data.id'));
    expect($count->counted_by)->toBe($owner->id)
        ->and($count->loss_value)->toBe(31875);

    $adjustment = StockMovement::query()->where('type', 'count_adjust')->firstOrFail();
    expect($adjustment->product_id)->toBe($product->id)
        ->and($adjustment->lot_id)->toBeNull()
        ->and($adjustment->weight_kg)->toBe('-1.500');
});

it('stores a positive correction with negative loss value and leaves lots unchanged', function () {
    [, $token] = countUser('owner', 'correction');
    $product = countProduct();
    $lot = countLot($product, 21250, '2026-10-22');
    countMovement($product, $lot, '20.000');

    $this->withToken($token)
        ->postJson('/api/v1/stock-counts', [
            'product_id' => $product->id,
            'counted_weight_kg' => '21.000',
            'counted_at' => '2026-10-22',
        ])
        ->assertCreated()
        ->assertJsonPath('data.diff_kg', '1.000')
        ->assertJsonPath('data.loss_value', -21250);

    expect(StockMovement::query()->where('type', 'count_adjust')->value('lot_id'))->toBeNull()
        ->and($lot->fresh()->clean_weight_kg)->toBe('20.000');
});

it('uses weighted average unit cost from remaining lots', function () {
    [, $token] = countUser('owner', 'weighted');
    $product = countProduct();
    $oldLot = countLot($product, 20000, '2026-10-20');
    $newLot = countLot($product, 22000, '2026-10-21');
    countMovement($product, $oldLot, '10.000');
    countMovement($product, $newLot, '20.000');

    $this->withToken($token)
        ->postJson('/api/v1/stock-counts', [
            'product_id' => $product->id,
            'counted_weight_kg' => '29.000',
            'counted_at' => '2026-10-22',
        ])
        ->assertCreated()
        ->assertJsonPath('data.system_weight_kg', '30.000')
        ->assertJsonPath('data.diff_kg', '-1.000')
        ->assertJsonPath('data.unit_cost_avg', 21333)
        ->assertJsonPath('data.loss_value', 21333);
});

it('rejects cashier access to stock count history', function () {
    [, $cashierToken] = countUser('kasir', 'denied');

    $this->withToken($cashierToken)
        ->getJson('/api/v1/stock-counts')
        ->assertForbidden();
});

it('requires authentication and validates stock count input', function () {
    $this->getJson('/api/v1/stock-counts')->assertUnauthorized();

    [, $ownerToken] = countUser('owner', 'validation');
    $product = countProduct();
    $this->withToken($ownerToken)
        ->postJson('/api/v1/stock-counts', [
            'product_id' => $product->id,
            'counted_weight_kg' => '-1.000',
            'counted_at' => 'invalid-date',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['counted_weight_kg', 'counted_at']);
});
