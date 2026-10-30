<?php

use App\Models\AuditLog;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function ownerPurchaseFixture(string $role = 'owner'): array
{
    static $sequence = 0;
    $sequence++;
    $user = User::factory()->create(['role' => $role, 'username' => $role.'-owner-purchase-'.$sequence]);
    $token = $user->createToken('owner-purchase-test')->plainTextToken;
    $supplier = Supplier::query()->create(['name' => 'Pemasok Test']);
    $purchase = $supplier->purchases()->create([
        'purchased_at' => '2026-10-22',
        'gross_weight_kg' => '100.000',
        'price_per_kg' => 17000,
        'total_cost' => 1700000,
        'notes' => 'Catatan awal',
        'status' => 'open',
        'created_by' => $user->id,
    ]);
    $product = Product::query()->create([
        'name' => 'Bawang Test '.$sequence,
        'price_retail' => 27500,
        'price_3kg' => 26000,
        'price_5kg' => 24000,
    ]);

    return [$user, $token, $supplier, $purchase, $product];
}

function ownerLotPayload(int $productId, string $weight): array
{
    return [
        'product_id' => $productId,
        'clean_weight_kg' => $weight,
        'cleaned_at' => '2026-10-22',
        'notes' => 'Lot uji',
    ];
}

function finalizeOwnerPurchase(string $suffix = 'finalized'): array
{
    [$owner, $token, $supplier, $purchase, $product] = ownerPurchaseFixture();
    test()->withToken($token)->postJson("/api/v1/purchases/{$purchase->id}/lots", ownerLotPayload($product->id, '80.000'))->assertCreated();
    test()->withToken($token)->postJson("/api/v1/purchases/{$purchase->id}/finalize")->assertOk();

    return [$owner, $token, $supplier, $purchase->fresh(), $product];
}

it('PB-01 updates and deletes an open purchase, removes its lots, and audits both actions', function () {
    [$owner, $token, $supplier, $purchase, $product] = ownerPurchaseFixture();
    $lot = $purchase->stockLots()->create(ownerLotPayload($product->id, '40.000'));

    $this->withToken($token)->putJson("/api/v1/purchases/{$purchase->id}", [
        'supplier_id' => $supplier->id,
        'purchased_at' => '2026-10-23',
        'gross_weight_kg' => '100.000',
        'price_per_kg' => 18000,
        'notes' => 'Diperbarui',
    ])->assertOk()->assertJsonPath('data.notes', 'Diperbarui')->assertJsonPath('data.total_cost', 1800000);

    $this->withToken($token)->deleteJson("/api/v1/purchases/{$purchase->id}")->assertOk();

    expect(Purchase::query()->find($purchase->id))->toBeNull()
        ->and(StockLot::query()->find($lot->id))->toBeNull()
        ->and(AuditLog::query()->where('entity_id', $purchase->id)->pluck('action')->all())
        ->toContain('purchase.updated', 'purchase.deleted');
});

it('PB-02 permits only notes on a finalized purchase and rejects purchase and lot mutations with the standard conflict', function () {
    [, $token, , $purchase, $product] = finalizeOwnerPurchase();

    $this->withToken($token)->putJson("/api/v1/purchases/{$purchase->id}", ['notes' => 'Catatan final'])->assertOk();
    $conflict = ['message' => 'Kulak yang sudah final tidak dapat diubah.'];

    $this->withToken($token)->putJson("/api/v1/purchases/{$purchase->id}", ['gross_weight_kg' => '99.000'])->assertConflict()->assertExactJson($conflict);
    $this->withToken($token)->deleteJson("/api/v1/purchases/{$purchase->id}")->assertConflict()->assertExactJson($conflict);
    $this->withToken($token)->postJson("/api/v1/purchases/{$purchase->id}/lots", ownerLotPayload($product->id, '1.000'))->assertConflict()->assertExactJson($conflict);
    $lot = $purchase->stockLots()->firstOrFail();
    $this->withToken($token)->putJson("/api/v1/purchases/{$purchase->id}/lots/{$lot->id}", ownerLotPayload($product->id, '79.000'))->assertConflict()->assertExactJson($conflict);
    $this->withToken($token)->deleteJson("/api/v1/purchases/{$purchase->id}/lots/{$lot->id}")->assertConflict()->assertExactJson($conflict);
});

it('PB-03 rejects lot totals above gross weight at add, update, gross-weight update, and finalize, while equality passes', function () {
    [, $token, $supplier, $purchase, $product] = ownerPurchaseFixture();
    $this->withToken($token)->postJson("/api/v1/purchases/{$purchase->id}/lots", ownerLotPayload($product->id, '100.001'))
        ->assertUnprocessable()->assertJsonValidationErrors(['clean_weight_kg']);
    $lot = $purchase->stockLots()->create(ownerLotPayload($product->id, '100.000'));

    $this->withToken($token)->putJson("/api/v1/purchases/{$purchase->id}/lots/{$lot->id}", ownerLotPayload($product->id, '100.001'))
        ->assertUnprocessable()->assertJsonValidationErrors(['clean_weight_kg']);
    $this->withToken($token)->putJson("/api/v1/purchases/{$purchase->id}", [
        'supplier_id' => $supplier->id, 'purchased_at' => '2026-10-22', 'gross_weight_kg' => '99.999',
        'price_per_kg' => 17000, 'notes' => 'terlalu kecil',
    ])->assertUnprocessable()->assertJsonValidationErrors(['gross_weight_kg']);
    $lot->update(['clean_weight_kg' => '100.001']);
    $this->withToken($token)->postJson("/api/v1/purchases/{$purchase->id}/finalize")
        ->assertUnprocessable()->assertJsonValidationErrors(['gross_weight_kg']);
    $lot->update(['clean_weight_kg' => '100.000']);
    $this->withToken($token)->postJson("/api/v1/purchases/{$purchase->id}/finalize")->assertOk();

    [, $equalToken, , $equalPurchase, $equalProduct] = ownerPurchaseFixture();
    $this->withToken($equalToken)->postJson("/api/v1/purchases/{$equalPurchase->id}/lots", ownerLotPayload($equalProduct->id, '100.000'))->assertCreated();
    $this->withToken($equalToken)->postJson("/api/v1/purchases/{$equalPurchase->id}/finalize")->assertOk();
});

it('PB-04 requires a lot to finalize and rejects zero or negative lot weights', function () {
    [, $token, , $purchase, $product] = ownerPurchaseFixture();
    $this->withToken($token)->postJson("/api/v1/purchases/{$purchase->id}/finalize")->assertConflict();
    foreach (['0.000', '-1.000'] as $weight) {
        $this->withToken($token)->postJson("/api/v1/purchases/{$purchase->id}/lots", ownerLotPayload($product->id, $weight))
            ->assertUnprocessable()->assertJsonValidationErrors(['clean_weight_kg']);
    }
});

it('PB-05 finalizes the documented 100 kilogram batch at 21250 per clean kilogram and rejects finalizing twice', function () {
    [, $token, , $purchase, $product] = ownerPurchaseFixture();
    foreach (['40.000', '25.000', '15.000'] as $index => $weight) {
        $batchProduct = $index === 0 ? $product : Product::query()->create([
            'name' => 'Batch '.$index, 'price_retail' => 27500, 'price_3kg' => 26000, 'price_5kg' => 24000,
        ]);
        $this->withToken($token)->postJson("/api/v1/purchases/{$purchase->id}/lots", ownerLotPayload($batchProduct->id, $weight))->assertCreated();
    }
    $this->withToken($token)->postJson("/api/v1/purchases/{$purchase->id}/finalize")
        ->assertOk()->assertJsonPath('data.cost_per_clean_kg', 21250)->assertJsonPath('data.clean_weight_kg', '80.000');
    $this->withToken($token)->postJson("/api/v1/purchases/{$purchase->id}/finalize")
        ->assertConflict()->assertJsonPath('message', 'Kulak yang sudah final tidak dapat diubah.');
    expect(StockMovement::query()->where('type', 'in_clean')->count())->toBe(3);
});

it('PB-06 returns purchase status, editability, lot totals, cleaning shrink, and lots', function () {
    [, $token, , $purchase, $product] = ownerPurchaseFixture();
    $this->withToken($token)->postJson("/api/v1/purchases/{$purchase->id}/lots", ownerLotPayload($product->id, '80.000'))->assertCreated();

    $this->withToken($token)->getJson("/api/v1/purchases/{$purchase->id}")->assertOk()
        ->assertJsonPath('data.status', 'open')->assertJsonPath('data.editable', true)
        ->assertJsonPath('data.lots_total_kg', '80.000')->assertJsonPath('data.cleaning_shrink_kg', '20.000')
        ->assertJsonCount(1, 'data.lots');
});

it('PB-07 writes purchase update, deletion, and finalization audit actions', function () {
    [$owner, $token, $supplier, $purchase, $product] = ownerPurchaseFixture();
    $this->withToken($token)->putJson("/api/v1/purchases/{$purchase->id}", [
        'supplier_id' => $supplier->id, 'purchased_at' => '2026-10-22', 'gross_weight_kg' => '100.000', 'price_per_kg' => 17000,
    ])->assertOk();
    $this->withToken($token)->postJson("/api/v1/purchases/{$purchase->id}/lots", ownerLotPayload($product->id, '80.000'))->assertCreated();
    $this->withToken($token)->postJson("/api/v1/purchases/{$purchase->id}/finalize")->assertOk();
    expect(AuditLog::query()->where('user_id', $owner->id)->pluck('action')->all())
        ->toContain('purchase.updated', 'purchase.finalized');

    [, $deleteToken, , $deletePurchase] = ownerPurchaseFixture();
    $this->withToken($deleteToken)->deleteJson("/api/v1/purchases/{$deletePurchase->id}")->assertOk();
    expect(AuditLog::query()->where('entity_id', $deletePurchase->id)->where('action', 'purchase.deleted')->exists())->toBeTrue();
});

it('PB-08 denies cashiers on all purchase and lot endpoints', function () {
    [, $ownerToken, , $purchase, $product] = ownerPurchaseFixture();
    $this->withToken($ownerToken)->postJson("/api/v1/purchases/{$purchase->id}/lots", ownerLotPayload($product->id, '80.000'))->assertCreated();
    $lot = $purchase->stockLots()->firstOrFail();
    $cashier = User::factory()->create(['role' => 'kasir', 'username' => 'cashier-owner-purchase']);
    $cashierToken = $cashier->createToken('owner-purchase-test')->plainTextToken;
    $this->app['auth']->forgetGuards();

    $this->withToken($cashierToken)->getJson('/api/v1/purchases')->assertForbidden();
    $this->withToken($cashierToken)->getJson("/api/v1/purchases/{$purchase->id}")->assertForbidden();
    $this->withToken($cashierToken)->postJson('/api/v1/purchases', [])->assertForbidden();
    $this->withToken($cashierToken)->putJson("/api/v1/purchases/{$purchase->id}", [])->assertForbidden();
    $this->withToken($cashierToken)->deleteJson("/api/v1/purchases/{$purchase->id}")->assertForbidden();
    $this->withToken($cashierToken)->postJson("/api/v1/purchases/{$purchase->id}/lots", [])->assertForbidden();
    $this->withToken($cashierToken)->putJson("/api/v1/purchases/{$purchase->id}/lots/{$lot->id}", [])->assertForbidden();
    $this->withToken($cashierToken)->deleteJson("/api/v1/purchases/{$purchase->id}/lots/{$lot->id}")->assertForbidden();
    $this->withToken($cashierToken)->postJson("/api/v1/purchases/{$purchase->id}/finalize")->assertForbidden();
});
