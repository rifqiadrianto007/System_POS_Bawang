<?php

use App\Models\Product;
use App\Models\Purchase;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function returnUser(string $role, string $suffix): array
{
    $user = User::factory()->create([
        'role' => $role,
        'username' => $role.'-return-'.$suffix,
    ]);

    return [$user, $user->createToken('void-return-test')->plainTextToken];
}

function returnProduct(): Product
{
    return Product::query()->create([
        'name' => 'Merah A',
        'price_retail' => 27500,
        'price_3kg' => 26000,
        'price_5kg' => 24000,
    ]);
}

function returnLot(Product $product, User $owner, string $weight, int $unitCost, string $date): StockLot
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

function returnPayload(int $productId): array
{
    return [
        'client_uuid' => 'af9c6a52-5f55-4f4e-9d4a-0c5f9d1a7b11',
        'customer_category' => 'ecer',
        'items' => [
            ['product_id' => $productId, 'weight_kg' => '6.000'],
        ],
        'payment_method' => 'cash',
        'final_total' => 144000,
        'occurred_at' => '2026-10-22T10:00:00+07:00',
    ];
}

it('returns allocated stock to the same lots when an owner voids a transaction', function () {
    [$cashier] = returnUser('kasir', 'cashier');
    [$owner, $ownerToken] = returnUser('owner', 'void');
    $product = returnProduct();
    $oldLot = returnLot($product, $owner, '4.000', 21250, '2026-10-20');
    $newLot = returnLot($product, $owner, '5.000', 22000, '2026-10-21');

    $transactionResponse = $this->withToken($ownerToken)
        ->postJson('/api/v1/transactions', returnPayload($product->id))
        ->assertCreated();
    $transactionId = $transactionResponse->json('data.id');

    expect((float) StockMovement::query()->where('type', 'sale')->sum('weight_kg'))->toBe(-6.0);

    $this->withToken($ownerToken)
        ->postJson("/api/v1/transactions/{$transactionId}/void", ['reason' => 'Koreksi stok'])
        ->assertOk()
        ->assertJsonPath('data.status', 'void');

    $returns = StockMovement::query()
        ->where('type', 'void_return')
        ->orderBy('lot_id')
        ->get();

    expect($returns)->toHaveCount(2)
        ->and($returns->pluck('lot_id')->all())->toBe([$oldLot->id, $newLot->id])
        ->and($returns->pluck('weight_kg')->map(fn (mixed $weight): string => (string) $weight)->all())
        ->toBe(['4.000', '2.000'])
        ->and($returns->pluck('unit_cost')->all())->toBe([21250, 22000])
        ->and($returns->every(fn (StockMovement $movement): bool => $movement->ref_id === $transactionId))->toBeTrue();

    expect((float) StockMovement::query()->where('type', 'sale')->sum('weight_kg'))->toBe(-6.0)
        ->and((float) StockMovement::query()->where('type', 'void_return')->sum('weight_kg'))->toBe(6.0)
        ->and((float) StockMovement::query()->sum('weight_kg'))->toBe(9.0);
});

it('does not create duplicate return movements when a void is attempted twice', function () {
    [$owner, $ownerToken] = returnUser('owner', 'repeat');
    $product = returnProduct();
    returnLot($product, $owner, '2.000', 21250, '2026-10-21');

    $transactionId = $this->withToken($ownerToken)
        ->postJson('/api/v1/transactions', returnPayload($product->id))
        ->json('data.id');

    $this->withToken($ownerToken)
        ->postJson("/api/v1/transactions/{$transactionId}/void", ['reason' => 'Koreksi'])
        ->assertOk();
    $this->withToken($ownerToken)
        ->postJson("/api/v1/transactions/{$transactionId}/void", ['reason' => 'Koreksi kedua'])
        ->assertStatus(409);

    expect(StockMovement::query()->where('type', 'void_return')->count())->toBe(2);
});
