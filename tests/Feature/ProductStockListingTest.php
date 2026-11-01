<?php

use App\Models\Product;
use App\Models\Purchase;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function catalogStockUser(string $role): array
{
    $user = User::factory()->create(['role' => $role]);

    return [$user, $user->createToken('catalog-stock')->plainTextToken];
}

function catalogStockProduct(string $name, bool $active = true): Product
{
    return Product::query()->create([
        'name' => $name, 'price_retail' => 27500, 'price_3kg' => 26000, 'price_5kg' => 24000, 'is_active' => $active,
    ]);
}

function catalogLot(Product $product, string $weight, int $unitCost): StockLot
{
    $owner = User::query()->where('role', 'owner')->first() ?? User::factory()->create(['role' => 'owner']);
    $supplier = Supplier::query()->create(['name' => 'Pemasok '.$product->id]);
    $purchase = Purchase::query()->create([
        'supplier_id' => $supplier->id, 'purchased_at' => '2026-10-22', 'gross_weight_kg' => $weight,
        'price_per_kg' => $unitCost, 'total_cost' => 0, 'status' => 'finalized', 'created_by' => $owner->id,
    ]);
    $lot = $purchase->stockLots()->create([
        'product_id' => $product->id, 'clean_weight_kg' => $weight, 'unit_cost' => $unitCost, 'cleaned_at' => '2026-10-22',
    ]);
    StockMovement::query()->create([
        'product_id' => $product->id, 'lot_id' => $lot->id, 'type' => 'in_clean', 'weight_kg' => $weight,
        'unit_cost' => $unitCost, 'occurred_at' => '2026-10-22 00:00:00', 'created_by' => $owner->id,
    ]);

    return $lot;
}

it('PR-01 returns every product to owner with ledger weight and per-lot rounded inventory value', function () {
    [, $token] = catalogStockUser('owner');
    $active = catalogStockProduct('Bawang Aktif');
    $inactive = catalogStockProduct('Bawang Nonaktif', false);
    $lotA = catalogLot($active, '10.000', 21250);
    $lotB = catalogLot($active, '10.000', 23000);
    StockMovement::query()->create([
        'product_id' => $active->id, 'lot_id' => $lotA->id, 'type' => 'sale', 'weight_kg' => '-1.000',
        'unit_cost' => 21250, 'occurred_at' => '2026-10-23 00:00:00',
    ]);

    $response = $this->withToken($token)->getJson('/api/v1/products')->assertOk()->assertJsonCount(2, 'data');
    $row = collect($response->json('data'))->firstWhere('id', $active->id);
    expect($row['is_active'])->toBeTrue()->and($row['stock_kg'])->toBe('19.000')->and($row['stock_value'])->toBe(421250);
    expect(collect($response->json('data'))->pluck('name')->all())->toBe(['Bawang Aktif', 'Bawang Nonaktif']);
});

it('PR-02 lists only active products to a cashier and recursively excludes every stock key', function () {
    [, $token] = catalogStockUser('kasir');
    catalogStockProduct('Bawang Aktif');
    catalogStockProduct('Bawang Nonaktif', false);
    $body = $this->withToken($token)->getJson('/api/v1/products')->assertOk()->assertJsonCount(1, 'data')->json();
    $forbidden = ['stock_kg', 'stock_value', 'stock', 'inventory_value', 'stock_weight_kg', 'lots'];
    $scan = function (mixed $value) use (&$scan, $forbidden): void {
        if (! is_array($value)) {
            return;
        }
        foreach ($value as $key => $nested) {
            expect(in_array((string) $key, $forbidden, true))->toBeFalse("Forbidden stock key: {$key}");
            $scan($nested);
        }
    };
    $scan($body);
});

it('PR-03 returns zero weight and value for a product without stock movements or lots', function () {
    [, $token] = catalogStockUser('owner');
    $product = catalogStockProduct('Kosong');
    $response = $this->withToken($token)->getJson('/api/v1/products')->assertOk();
    $row = collect($response->json('data'))->firstWhere('id', $product->id);
    expect($row['stock_kg'])->toBe('0.000')->and($row['stock_value'])->toBe(0);
});

it('PR-04 filters owner products using active equal to zero or one', function () {
    [, $token] = catalogStockUser('owner');
    catalogStockProduct('Aktif', true);
    catalogStockProduct('Nonaktif', false);
    $this->withToken($token)->getJson('/api/v1/products?active=0')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Nonaktif');
    $this->withToken($token)->getJson('/api/v1/products?active=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Aktif');
});

it('PR-05 uses a constant number of database queries for five and thirty products', function () {
    [, $token] = catalogStockUser('owner');
    foreach (range(1, 5) as $number) {
        catalogStockProduct('Produk '.str_pad((string) $number, 2, '0', STR_PAD_LEFT));
    }
    $queryCounts = [];
    $recordQueries = false;
    $currentCount = 0;
    DB::listen(function (QueryExecuted $query) use (&$recordQueries, &$currentCount): void {
        if ($recordQueries) {
            $currentCount++;
        }
    });
    $this->withToken($token)->getJson('/api/v1/products?active=1')->assertOk();
    $this->app['auth']->forgetGuards();
    $recordQueries = true;
    $this->withToken($token)->getJson('/api/v1/products?active=1')->assertOk()->assertJsonCount(5, 'data');
    $recordQueries = false;
    $queryCounts[] = $currentCount;

    foreach (range(6, 30) as $number) {
        catalogStockProduct('Produk '.str_pad((string) $number, 2, '0', STR_PAD_LEFT));
    }
    $currentCount = 0;
    $this->app['auth']->forgetGuards();
    $recordQueries = true;
    $this->withToken($token)->getJson('/api/v1/products?active=1')->assertOk()->assertJsonCount(30, 'data');
    $recordQueries = false;
    $queryCounts[] = $currentCount;

    expect($queryCounts[0])->toBe($queryCounts[1]);
});
