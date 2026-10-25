<?php

use App\Models\AuditLog;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function purchaseUser(string $role, string $suffix): array
{
    $user = User::factory()->create([
        'role' => $role,
        'username' => $role.'-purchase-'.$suffix,
    ]);

    return [$user, $user->createToken('purchase-test')->plainTextToken];
}

function supplierPayload(string $name = 'Supplier Bawang'): array
{
    return [
        'name' => $name,
        'phone' => '08123456789',
        'note' => 'Kirim pagi',
    ];
}

function purchasePayload(int $supplierId): array
{
    return [
        'supplier_id' => $supplierId,
        'purchased_at' => '2026-10-22',
        'gross_weight_kg' => '100.000',
        'price_per_kg' => 17000,
        'notes' => 'Bawang kotor',
    ];
}

it('allows an owner to manage suppliers and records supplier audits', function () {
    [$owner, $token] = purchaseUser('owner', 'supplier');

    $created = $this->withToken($token)
        ->postJson('/api/v1/suppliers', supplierPayload())
        ->assertCreated()
        ->assertJsonPath('data.name', 'Supplier Bawang')
        ->assertJsonPath('data.phone', '08123456789');

    $supplierId = $created->json('data.id');

    $this->withToken($token)
        ->getJson('/api/v1/suppliers')
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $this->withToken($token)
        ->putJson("/api/v1/suppliers/{$supplierId}", ['name' => 'Supplier Baru'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Supplier Baru')
        ->assertJsonPath('data.phone', '08123456789')
        ->assertJsonPath('data.note', 'Kirim pagi');

    expect(AuditLog::query()->where('user_id', $owner->id)->pluck('action')->all())
        ->toContain('supplier.created', 'supplier.updated');
});

it('allows an owner to create and view a purchase with rounded total cost', function () {
    [$owner, $token] = purchaseUser('owner', 'purchase');
    $supplier = Supplier::query()->create(supplierPayload());

    $created = $this->withToken($token)
        ->postJson('/api/v1/purchases', purchasePayload($supplier->id))
        ->assertCreated()
        ->assertJsonPath('data.supplier_id', $supplier->id)
        ->assertJsonPath('data.gross_weight_kg', '100.000')
        ->assertJsonPath('data.price_per_kg', 17000)
        ->assertJsonPath('data.total_cost', 1700000)
        ->assertJsonPath('data.status', 'open');

    $purchase = Purchase::query()->findOrFail($created->json('data.id'));
    expect($purchase->created_by)->toBe($owner->id)
        ->and($purchase->total_cost)->toBe(1700000);

    $this->withToken($token)
        ->getJson("/api/v1/purchases/{$purchase->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $purchase->id)
        ->assertJsonPath('data.supplier.name', 'Supplier Bawang');

    $this->withToken($token)
        ->getJson('/api/v1/purchases')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('rounds fractional purchase cost half up without floating point arithmetic', function () {
    [, $token] = purchaseUser('owner', 'rounding');
    $supplier = Supplier::query()->create(['name' => 'Supplier Pecahan']);

    $response = $this->withToken($token)->postJson('/api/v1/purchases', [
        'supplier_id' => $supplier->id,
        'purchased_at' => '2026-10-22',
        'gross_weight_kg' => '0.333',
        'price_per_kg' => 17001,
    ]);

    $response->assertCreated()->assertJsonPath('data.total_cost', 5661);
});

it('rejects invalid purchase data and non-owner access', function () {
    [$kasir, $cashierToken] = purchaseUser('kasir', 'denied');
    $supplier = Supplier::query()->create(['name' => 'Supplier Valid']);

    $this->withToken($cashierToken)
        ->getJson('/api/v1/suppliers')
        ->assertForbidden();

    $this->withToken($cashierToken)
        ->postJson('/api/v1/purchases', purchasePayload($supplier->id))
        ->assertForbidden();

    [, $ownerToken] = purchaseUser('owner', 'invalid');
    $this->app['auth']->forgetGuards();

    $this->withToken($ownerToken)
        ->postJson('/api/v1/purchases', [
            'supplier_id' => $supplier->id,
            'purchased_at' => 'not-a-date',
            'gross_weight_kg' => '0.000',
            'price_per_kg' => -1,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['purchased_at', 'gross_weight_kg', 'price_per_kg']);

    expect($kasir->role)->toBe('kasir');
});
