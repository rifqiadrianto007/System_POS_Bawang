<?php

use App\Models\AuditLog;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function lotUser(string $role, string $suffix): array
{
    $user = User::factory()->create([
        'role' => $role,
        'username' => $role.'-lot-'.$suffix,
    ]);

    return [$user, $user->createToken('lot-test')->plainTextToken];
}

function openPurchase(User $owner): Purchase
{
    $supplier = Supplier::query()->create(['name' => 'Supplier Lot']);

    return $supplier->purchases()->create([
        'purchased_at' => '2026-10-22',
        'gross_weight_kg' => '100.000',
        'price_per_kg' => 17000,
        'total_cost' => 1700000,
        'status' => 'open',
        'created_by' => $owner->id,
    ]);
}

function lotProduct(string $name): Product
{
    return Product::query()->create([
        'name' => $name,
        'price_retail' => 27500,
        'price_3kg' => 26000,
        'price_5kg' => 24000,
    ]);
}

function lotPayload(int $productId, string $weight): array
{
    return [
        'product_id' => $productId,
        'clean_weight_kg' => $weight,
        'cleaned_at' => '2026-10-22',
        'notes' => 'Hasil sortir grade',
    ];
}

it('adds lots only to an open purchase and returns lot data', function () {
    [$owner, $token] = lotUser('owner', 'add');
    $purchase = openPurchase($owner);
    $product = lotProduct('Merah A');

    $response = $this->withToken($token)
        ->postJson("/api/v1/purchases/{$purchase->id}/lots", lotPayload($product->id, '40.000'));

    $response->assertCreated()
        ->assertJsonPath('data.purchase_id', $purchase->id)
        ->assertJsonPath('data.product_id', $product->id)
        ->assertJsonPath('data.clean_weight_kg', '40.000')
        ->assertJsonPath('data.unit_cost', null);

    expect($purchase->fresh()->stockLots)->toHaveCount(1)
        ->and(AuditLog::query()->where('action', 'purchase.lot_added')->count())->toBe(1);
});

it('finalizes the example batch at 21250 per clean kilogram and creates movements', function () {
    [$owner, $token] = lotUser('owner', 'finalize');
    $purchase = openPurchase($owner);
    $products = [
        lotProduct('Merah A'),
        lotProduct('Merah B'),
        lotProduct('Merah C'),
    ];

    foreach (array_map(null, $products, ['40.000', '25.000', '15.000']) as [$product, $weight]) {
        $this->withToken($token)
            ->postJson("/api/v1/purchases/{$purchase->id}/lots", lotPayload($product->id, $weight))
            ->assertCreated();
    }

    // PB-04 uses the standard 409 conflict message for finalization without lots.
    $this->withToken($token)
        ->postJson("/api/v1/purchases/{$purchase->id}/finalize")
        ->assertOk()
        ->assertJsonPath('data.status', 'finalized')
        ->assertJsonPath('data.cost_per_clean_kg', 21250)
        ->assertJsonPath('data.clean_weight_kg', '80.000')
        ->assertJsonCount(3, 'data.stock_lots');

    $purchase->refresh();
    expect($purchase->status)->toBe('finalized')
        ->and($purchase->cost_per_clean_kg)->toBe(21250)
        ->and($purchase->clean_weight_kg)->toBe('80.000')
        ->and($purchase->finalized_at)->not->toBeNull()
        ->and($purchase->stockLots->pluck('unit_cost')->unique()->all())->toBe([21250]);

    expect(StockMovement::query()->where('type', 'in_clean')->count())->toBe(3)
        ->and((float) StockMovement::query()->where('type', 'in_clean')->sum('weight_kg'))->toBe(80.0)
        ->and(AuditLog::query()->where('action', 'purchase.finalized')->count())->toBe(1);
});

it('rejects finalization without lots and rejects changes after finalization', function () {
    [$owner, $token] = lotUser('owner', 'locked');
    $purchase = openPurchase($owner);

    $this->withToken($token)
        ->postJson("/api/v1/purchases/{$purchase->id}/finalize")
        ->assertStatus(409)
        ->assertJsonPath('message', 'Kulak yang sudah final tidak dapat diubah.');

    $product = lotProduct('Putih A');
    $this->withToken($token)
        ->postJson("/api/v1/purchases/{$purchase->id}/lots", lotPayload($product->id, '80.000'))
        ->assertCreated();
    $this->withToken($token)
        ->postJson("/api/v1/purchases/{$purchase->id}/finalize")
        ->assertOk();

    $this->withToken($token)
        ->postJson("/api/v1/purchases/{$purchase->id}/lots", lotPayload($product->id, '1.000'))
        ->assertStatus(409);

    $lotId = $purchase->fresh()->stockLots->first()->id;
    $this->withToken($token)
        ->deleteJson("/api/v1/purchases/{$purchase->id}/lots/{$lotId}")
        ->assertStatus(409);

    expect($purchase->fresh()->stockLots)->toHaveCount(1);
});

it('restricts lot operations to owners and validates lot input', function () {
    [$owner] = lotUser('owner', 'access-owner');
    [$cashier, $cashierToken] = lotUser('kasir', 'access-cashier');
    $purchase = openPurchase($owner);
    $product = lotProduct('Merah D');

    $this->withToken($cashierToken)
        ->postJson("/api/v1/purchases/{$purchase->id}/lots", lotPayload($product->id, '1.000'))
        ->assertForbidden();

    [, $ownerToken] = lotUser('owner', 'validation');
    $this->app['auth']->forgetGuards();

    $this->withToken($ownerToken)
        ->postJson("/api/v1/purchases/{$purchase->id}/lots", [
            'product_id' => $product->id,
            'clean_weight_kg' => '0.000',
            'cleaned_at' => 'invalid-date',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['clean_weight_kg', 'cleaned_at']);

    expect($cashier->role)->toBe('kasir');
});
