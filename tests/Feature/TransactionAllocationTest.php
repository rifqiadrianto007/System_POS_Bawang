<?php

use App\Models\Product;
use App\Models\Purchase;
use App\Models\SaleAllocation;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function allocationUser(string $role, string $suffix): array
{
    $user = User::factory()->create([
        'role' => $role,
        'username' => $role.'-allocation-'.$suffix,
    ]);

    return [$user, $user->createToken('allocation-test')->plainTextToken];
}

function allocationProduct(): Product
{
    return Product::query()->create([
        'name' => 'Merah A',
        'price_retail' => 27500,
        'price_3kg' => 26000,
        'price_5kg' => 24000,
    ]);
}

function allocationLot(Product $product, User $owner, string $weight, int $unitCost, string $date): StockLot
{
    $supplier = Supplier::query()->create(['name' => 'Supplier '.$date]);
    $purchase = Purchase::query()->create([
        'supplier_id' => $supplier->id,
        'purchased_at' => $date,
        'gross_weight_kg' => $weight,
        'price_per_kg' => $unitCost,
        'total_cost' => $unitCost,
        'status' => 'finalized',
        'cost_per_clean_kg' => $unitCost,
        'clean_weight_kg' => $weight,
        'finalized_at' => $date.' 01:00:00',
        'created_by' => $owner->id,
    ]);
    $lot = $purchase->stockLots()->create([
        'product_id' => $product->id,
        'clean_weight_kg' => $weight,
        'unit_cost' => $unitCost,
        'cleaned_at' => $date,
    ]);
    StockMovement::query()->create([
        'product_id' => $product->id,
        'lot_id' => $lot->id,
        'type' => 'in_clean',
        'weight_kg' => $weight,
        'unit_cost' => $unitCost,
        'occurred_at' => $date.' 01:00:00',
        'created_by' => $owner->id,
    ]);

    return $lot;
}

function allocationPayload(int $productId, string $uuid = '7f9c6a52-5f55-4f4e-9d4a-0c5f9d1a7b11'): array
{
    return [
        'client_uuid' => $uuid,
        'customer_category' => 'ecer',
        'items' => [
            ['product_id' => $productId, 'weight_kg' => '6.000'],
        ],
        'payment_method' => 'cash',
        'final_total' => 144000,
        'occurred_at' => '2026-10-22T10:00:00+07:00',
    ];
}

it('allocates a sale across lots in FIFO order and calculates cogs', function () {
    [$owner, $token] = allocationUser('owner', 'fifo');
    $product = allocationProduct();
    $oldLot = allocationLot($product, $owner, '4.000', 21250, '2026-10-20');
    $newLot = allocationLot($product, $owner, '5.000', 22000, '2026-10-21');

    $response = $this->withToken($token)
        ->postJson('/api/v1/transactions', allocationPayload($product->id))
        ->assertCreated()
        ->assertJsonPath('data.items.0.cogs', 129000);

    $itemId = $response->json('data.items.0.id');
    $allocations = SaleAllocation::query()->where('transaction_item_id', $itemId)
        ->orderBy('id')->get();

    expect($allocations)->toHaveCount(2)
        ->and($allocations[0]->lot_id)->toBe($oldLot->id)
        ->and($allocations[0]->weight_kg)->toBe('4.000')
        ->and($allocations[0]->unit_cost)->toBe(21250)
        ->and($allocations[0]->is_shortfall)->toBeFalse()
        ->and($allocations[1]->lot_id)->toBe($newLot->id)
        ->and($allocations[1]->weight_kg)->toBe('2.000')
        ->and($allocations[1]->unit_cost)->toBe(22000)
        ->and($allocations[1]->is_shortfall)->toBeFalse();

    expect(StockMovement::query()->where('type', 'sale')->count())->toBe(2)
        ->and((float) StockMovement::query()->where('type', 'sale')->where('lot_id', $oldLot->id)->sum('weight_kg'))->toBe(-4.0)
        ->and((float) StockMovement::query()->where('type', 'sale')->where('lot_id', $newLot->id)->sum('weight_kg'))->toBe(-2.0);
});

it('creates a shortfall allocation when stock is insufficient', function () {
    [$owner, $token] = allocationUser('owner', 'shortfall');
    $product = allocationProduct();
    $lot = allocationLot($product, $owner, '2.000', 21250, '2026-10-21');

    $response = $this->withToken($token)
        ->postJson('/api/v1/transactions', allocationPayload($product->id, '8f9c6a52-5f55-4f4e-9d4a-0c5f9d1a7b12'))
        ->assertCreated()
        ->assertJsonPath('data.items.0.cogs', 127500);

    $allocations = SaleAllocation::query()
        ->where('transaction_item_id', $response->json('data.items.0.id'))
        ->orderBy('id')->get();

    expect($allocations)->toHaveCount(2)
        ->and($allocations[0]->lot_id)->toBe($lot->id)
        ->and($allocations[0]->weight_kg)->toBe('2.000')
        ->and($allocations[0]->is_shortfall)->toBeFalse()
        ->and($allocations[1]->lot_id)->toBe($lot->id)
        ->and($allocations[1]->weight_kg)->toBe('4.000')
        ->and($allocations[1]->unit_cost)->toBe(21250)
        ->and($allocations[1]->is_shortfall)->toBeTrue();

    expect(StockMovement::query()->where('type', 'sale')->count())->toBe(2);
});

it('creates a zero-cost shortfall when a product has no stock lot', function () {
    [, $token] = allocationUser('kasir', 'no-lot');
    $product = allocationProduct();

    $response = $this->withToken($token)
        ->postJson('/api/v1/transactions', allocationPayload($product->id, '9f9c6a52-5f55-4f4e-9d4a-0c5f9d1a7b13'))
        ->assertCreated()
        ->assertJsonMissingPath('data.items.0.cogs');

    $allocation = SaleAllocation::query()
        ->where('transaction_item_id', $response->json('data.items.0.id'))
        ->firstOrFail();

    expect($allocation->lot_id)->toBeNull()
        ->and($allocation->unit_cost)->toBe(0)
        ->and($allocation->is_shortfall)->toBeTrue()
        ->and(Transaction::query()->first()->status)->toBe('completed');
});
