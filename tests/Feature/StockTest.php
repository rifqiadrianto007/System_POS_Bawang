<?php

use App\Models\Product;
use App\Models\Purchase;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function stockUser(string $role, string $suffix): array
{
    $user = User::factory()->create([
        'role' => $role,
        'username' => $role.'-stock-'.$suffix,
    ]);

    return [$user, $user->createToken('stock-test')->plainTextToken];
}

function stockProduct(string $name): Product
{
    return Product::query()->create([
        'name' => $name,
        'price_retail' => 27500,
        'price_3kg' => 26000,
        'price_5kg' => 24000,
    ]);
}

function stockLot(Product $product, int $unitCost, string $cleanWeight, string $cleanedAt): StockLot
{
    $supplier = Supplier::query()->create(['name' => 'Supplier '.$product->name]);
    $creator = User::factory()->create(['role' => 'owner']);
    $purchase = Purchase::query()->create([
        'supplier_id' => $supplier->id,
        'purchased_at' => $cleanedAt,
        'gross_weight_kg' => $cleanWeight,
        'price_per_kg' => $unitCost,
        'total_cost' => $unitCost,
        'status' => 'finalized',
        'created_by' => $creator->id,
    ]);

    return StockLot::query()->create([
        'purchase_id' => $purchase->id,
        'product_id' => $product->id,
        'clean_weight_kg' => $cleanWeight,
        'unit_cost' => $unitCost,
        'cleaned_at' => $cleanedAt,
    ]);
}

function stockMovement(Product $product, StockLot $lot, string $weight, string $type = 'in_clean'): void
{
    StockMovement::query()->create([
        'product_id' => $product->id,
        'lot_id' => $lot->id,
        'type' => $type,
        'weight_kg' => $weight,
        'unit_cost' => $lot->unit_cost,
        'occurred_at' => '2026-10-22 01:00:00',
    ]);
}

it('calculates stock and inventory value from the movement ledger per product and lot', function () {
    [, $token] = stockUser('owner', 'ledger');
    $red = stockProduct('Merah A');
    $white = stockProduct('Putih A');
    $redOld = stockLot($red, 21250, '40.000', '2026-10-20');
    $redNew = stockLot($red, 22000, '25.000', '2026-10-21');
    $whiteLot = stockLot($white, 20000, '10.000', '2026-10-21');

    stockMovement($red, $redOld, '40.000');
    stockMovement($red, $redOld, '-5.000', 'sale');
    stockMovement($red, $redNew, '25.000');
    stockMovement($white, $whiteLot, '10.000');

    $this->withToken($token)
        ->getJson('/api/v1/stock')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.product_name', 'Merah A')
        ->assertJsonPath('data.0.stock_weight_kg', '60.000')
        ->assertJsonPath('data.0.inventory_value', 1293750)
        ->assertJsonPath('data.0.lots.0.stock_weight_kg', '35.000')
        ->assertJsonPath('data.0.lots.0.unit_cost', 21250)
        ->assertJsonPath('data.0.lots.1.stock_weight_kg', '25.000')
        ->assertJsonPath('data.0.lots.1.unit_cost', 22000)
        ->assertJsonPath('data.1.product_name', 'Putih A')
        ->assertJsonPath('data.1.stock_weight_kg', '10.000')
        ->assertJsonPath('data.1.inventory_value', 200000);
});

it('reflects ledger changes without editing stored stock totals', function () {
    [, $token] = stockUser('owner', 'refresh');
    $product = stockProduct('Merah B');
    $lot = stockLot($product, 21250, '20.000', '2026-10-22');
    stockMovement($product, $lot, '20.000');

    $this->withToken($token)
        ->getJson('/api/v1/stock')
        ->assertJsonPath('data.0.stock_weight_kg', '20.000');

    stockMovement($product, $lot, '-1.500', 'count_adjust');

    $this->withToken($token)
        ->getJson('/api/v1/stock')
        ->assertJsonPath('data.0.stock_weight_kg', '18.500')
        ->assertJsonPath('data.0.inventory_value', 393125);
});

it('restricts stock summaries to owners and requires authentication', function () {
    [, $cashierToken] = stockUser('kasir', 'denied');

    $this->withToken($cashierToken)
        ->getJson('/api/v1/stock')
        ->assertForbidden();
});

it('requires authentication for stock summaries', function () {
    $this->getJson('/api/v1/stock')->assertUnauthorized();
});
