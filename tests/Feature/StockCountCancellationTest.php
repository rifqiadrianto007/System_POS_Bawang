<?php

use App\Models\AuditLog;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\SaleAllocation;
use App\Models\StockCount;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function cnUser(string $role, string $suffix): array
{
    $user = User::factory()->create([
        'role' => $role,
        'username' => $role.'-cn-'.$suffix,
    ]);

    return [$user, $user->createToken('cn-test')->plainTextToken];
}

function cnProduct(string $name = 'Merah CN'): Product
{
    return Product::query()->create([
        'name' => $name,
        'price_retail' => 27500,
        'price_3kg' => 26000,
        'price_5kg' => 24000,
    ]);
}

function cnLot(Product $product, User $owner, string $weight = '20.000', int $unitCost = 21250): StockLot
{
    $supplier = Supplier::query()->create(['name' => 'Supplier CN '.uniqid()]);
    $purchase = Purchase::query()->create([
        'supplier_id' => $supplier->id,
        'purchased_at' => '2026-10-22',
        'gross_weight_kg' => $weight,
        'price_per_kg' => $unitCost,
        'total_cost' => $unitCost,
        'status' => 'finalized',
        'clean_weight_kg' => $weight,
        'cost_per_clean_kg' => $unitCost,
        'finalized_at' => '2026-10-22 01:00:00',
        'created_by' => $owner->id,
    ]);
    $lot = $purchase->stockLots()->create([
        'product_id' => $product->id,
        'clean_weight_kg' => $weight,
        'unit_cost' => $unitCost,
        'cleaned_at' => '2026-10-22',
    ]);
    StockMovement::query()->create([
        'product_id' => $product->id,
        'lot_id' => $lot->id,
        'type' => 'in_clean',
        'weight_kg' => $weight,
        'unit_cost' => $unitCost,
        'occurred_at' => '2026-10-22 01:00:00',
    ]);

    return $lot;
}

function cnCountPayload(Product $product, string $weight, string $at = '2026-10-22T02:00:00+07:00'): array
{
    return [
        'product_id' => $product->id,
        'counted_weight_kg' => $weight,
        'counted_at' => $at,
    ];
}

function cnSalePayload(Product $product, string $weight = '2.000', string $uuid = 'c1000000-0000-4000-8000-000000000001'): array
{
    $total = (int) bcmul($weight, '27500', 0);

    return [
        'client_uuid' => $uuid,
        'customer_category' => 'ecer',
        'items' => [['product_id' => $product->id, 'weight_kg' => $weight]],
        'payment_method' => 'cash',
        'final_total' => $total,
        'amount_paid' => $total,
        'occurred_at' => '2026-10-22T03:00:00+07:00',
    ];
}

function cnLotWeight(StockLot $lot): float
{
    return (float) StockMovement::query()->where('lot_id', $lot->id)->sum('weight_kg');
}

it('CN-01 cancels the latest count with an inverse movement and status metadata', function () {
    [$owner, $token] = cnUser('owner', 'latest');
    $product = cnProduct();
    $lot = cnLot($product, $owner);
    $count = $this->withToken($token)->postJson('/api/v1/stock-counts', cnCountPayload($product, '18.500'))
        ->assertCreated()->json('data.id');

    $response = $this->withToken($token)->deleteJson("/api/v1/stock-counts/{$count}", ['reason' => 'Salah timbang'])
        ->assertOk()->assertJsonPath('data.status', 'cancelled')
        ->assertJsonPath('data.cancel_reason', 'Salah timbang');

    expect(cnLotWeight($lot))->toBe(20.0)
        ->and(StockMovement::query()->where('ref_type', 'stock_count_cancelled')->where('ref_id', $count)->count())->toBe(1)
        ->and($response->json('data.cancelled_by_name'))->toBe($owner->name);
});

it('CN-02 only allows cancelling the latest active count for a product', function () {
    [, $token] = cnUser('owner', 'ordering');
    $product = cnProduct();
    cnLot($product, User::query()->where('role', 'owner')->firstOrFail());
    $old = $this->withToken($token)->postJson('/api/v1/stock-counts', cnCountPayload($product, '19.000', '2026-10-22T02:00:00+07:00'))->json('data.id');
    $this->withToken($token)->postJson('/api/v1/stock-counts', cnCountPayload($product, '18.000', '2026-10-22T03:00:00+07:00'))->assertCreated();

    $this->withToken($token)->deleteJson("/api/v1/stock-counts/{$old}", ['reason' => 'Koreksi lama'])
        ->assertConflict()->assertJsonPath('message', 'Hanya hitung ulang terakhir untuk produk ini yang dapat dibatalkan.');
});

it('CN-03 validates cancellation state, existence, reason, and owner role', function () {
    [$owner, $ownerToken] = cnUser('owner', 'validation');
    [$cashier] = cnUser('kasir', 'validation');
    $product = cnProduct();
    cnLot($product, $owner);
    $count = $this->withToken($ownerToken)->postJson('/api/v1/stock-counts', cnCountPayload($product, '19.000'))->json('data.id');

    $this->withToken($ownerToken)->deleteJson("/api/v1/stock-counts/{$count}", ['reason' => 'x'])->assertUnprocessable();
    $this->withToken($ownerToken)->deleteJson("/api/v1/stock-counts/{$count}", ['reason' => 'Batal'])->assertOk();
    $this->withToken($ownerToken)->deleteJson("/api/v1/stock-counts/{$count}", ['reason' => 'Batal lagi'])->assertConflict();
    $this->withToken($ownerToken)->deleteJson('/api/v1/stock-counts/999999', ['reason' => 'Tidak ada'])->assertNotFound();
    expect($cashier->fresh()->role)->toBe('kasir');
    Sanctum::actingAs($cashier);
    $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('user.role', 'kasir');
    $this->getJson('/api/v1/owner/ping')->assertForbidden();
    $this->deleteJson("/api/v1/stock-counts/{$count}", ['reason' => 'Kasir'])->assertForbidden();
});

it('CN-04 restores a mistaken count then backfills the sale shortfall', function () {
    [$owner, $token] = cnUser('owner', 'mistake');
    $product = cnProduct();
    $lot = cnLot($product, $owner);
    $count = $this->withToken($token)->postJson('/api/v1/stock-counts', cnCountPayload($product, '0.000'))->json('data.id');
    $transaction = $this->withToken($token)->postJson('/api/v1/transactions', cnSalePayload($product))->assertCreated();

    expect(cnLotWeight($lot))->toBe(0.0);
    $this->withToken($token)->deleteJson("/api/v1/stock-counts/{$count}", ['reason' => 'Salah input'])->assertOk();

    $allocation = SaleAllocation::query()->where('transaction_item_id', $transaction->json('data.items.0.id'))->firstOrFail();
    expect(cnLotWeight($lot))->toBe(18.0)
        ->and($allocation->fresh()->is_shortfall)->toBeFalse()
        ->and($allocation->fresh()->unit_cost)->toBe(21250);
});

it('CN-05 records the cancellation audit payload', function () {
    [$owner, $token] = cnUser('owner', 'audit');
    $product = cnProduct();
    cnLot($product, $owner);
    $count = $this->withToken($token)->postJson('/api/v1/stock-counts', cnCountPayload($product, '18.500'))->json('data.id');
    $this->withToken($token)->deleteJson("/api/v1/stock-counts/{$count}", ['reason' => 'Alasan audit'])->assertOk();

    $audit = AuditLog::query()->where('action', 'stock_count.cancelled')->firstOrFail();
    expect($audit->entity_id)->toBe($count)
        ->and($audit->payload)->toMatchArray([
            'product_id' => $product->id,
            'diff_kg' => '-1.500',
            'loss_value' => 31875,
            'reason' => 'Alasan audit',
        ]);
});

it('CN-06 lists active and cancelled counts and filters by status', function () {
    [$owner, $token] = cnUser('owner', 'list');
    $product = cnProduct();
    cnLot($product, $owner);
    $count = $this->withToken($token)->postJson('/api/v1/stock-counts', cnCountPayload($product, '19.000'))->json('data.id');
    $this->withToken($token)->deleteJson("/api/v1/stock-counts/{$count}", ['reason' => 'Batal'])->assertOk();
    $this->withToken($token)->postJson('/api/v1/stock-counts', cnCountPayload($product, '19.500', '2026-10-22T03:00:00+07:00'))->assertCreated();

    $this->withToken($token)->getJson('/api/v1/stock-counts')->assertOk()->assertJsonCount(2, 'data');
    $this->withToken($token)->getJson('/api/v1/stock-counts?status=cancelled')->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.status', 'cancelled');
});

it('CN-07 excludes cancelled counts from finance shrink totals and daily rows', function () {
    [$owner, $token] = cnUser('owner', 'finance');
    $product = cnProduct();
    cnLot($product, $owner);
    $count = $this->withToken($token)->postJson('/api/v1/stock-counts', cnCountPayload($product, '18.500'))->json('data.id');
    $this->withToken($token)->deleteJson("/api/v1/stock-counts/{$count}", ['reason' => 'Tidak jadi'])->assertOk();

    $this->withToken($token)->getJson('/api/v1/reports/finance?period=daily&date=2026-10-22')->assertOk()
        ->assertJsonPath('data.display_shrink_value', 0)
        ->assertJsonPath('data.daily.0.display_shrink_value', 0);
});

it('CN-08 keeps stock invariants after cancelling a count in the operation sequence', function () {
    [$owner, $token] = cnUser('owner', 'property');
    $product = cnProduct();
    $lot = cnLot($product, $owner);
    $count = $this->withToken($token)->postJson('/api/v1/stock-counts', cnCountPayload($product, '18.000'))->json('data.id');
    $this->withToken($token)->deleteJson("/api/v1/stock-counts/{$count}", ['reason' => 'Uji urutan'])->assertOk();

    $stock = (float) StockMovement::query()->where('product_id', $product->id)->sum('weight_kg');
    $lotStock = cnLotWeight($lot);
    expect($stock)->toBe($lotStock)->and($lotStock)->toBeGreaterThanOrEqual(0);
});
