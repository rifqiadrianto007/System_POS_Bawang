<?php

use App\Models\AuditLog;
use App\Models\Product;
use App\Models\ProductPriceHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function productUser(string $role): array
{
    $user = User::factory()->create([
        'role' => $role,
        'username' => $role.'-catalog',
    ]);

    return [$user, $user->createToken('test')->plainTextToken];
}

it('allows an owner to create a product and records an audit log', function () {
    [$owner, $token] = productUser('owner');

    $response = $this->withToken($token)->postJson('/api/v1/products', [
        'name' => 'Merah A',
        'price_retail' => 27500,
        'price_3kg' => 26000,
        'price_5kg' => 24000,
    ]);

    $product = Product::query()->first();

    $response->assertCreated()
        ->assertJsonPath('data.name', 'Merah A')
        ->assertJsonPath('data.price_retail', 27500);

    expect($product)->not->toBeNull()
        ->and(AuditLog::query()->where('action', 'product.created')->where('user_id', $owner->id)->exists())->toBeTrue();
});

it('allows an owner to update prices and records history and audit', function () {
    [$owner, $token] = productUser('owner');
    $product = Product::query()->create([
        'name' => 'Merah A',
        'price_retail' => 27500,
        'price_3kg' => 26000,
        'price_5kg' => 24000,
    ]);

    $response = $this->withToken($token)->putJson("/api/v1/products/{$product->id}", [
        'name' => 'Merah A',
        'price_retail' => 28000,
        'price_3kg' => 26500,
        'price_5kg' => 24500,
    ]);

    $response->assertOk()
        ->assertJsonPath('data.price_retail', 28000);

    $history = ProductPriceHistory::query()->first();
    $audit = AuditLog::query()->where('action', 'product.price_changed')->first();

    expect($history->product_id)->toBe($product->id)
        ->and($history->price_retail)->toBe(28000)
        ->and($history->price_3kg)->toBe(26500)
        ->and($history->price_5kg)->toBe(24500)
        ->and($history->changed_by)->toBe($owner->id)
        ->and($audit->entity_id)->toBe($product->id)
        ->and($audit->user_id)->toBe($owner->id);
});

it('does not block a price order that needs owner review', function () {
    [, $token] = productUser('owner');

    $this->withToken($token)->postJson('/api/v1/products', [
        'name' => 'Putih A',
        'price_retail' => 24000,
        'price_3kg' => 26000,
        'price_5kg' => 28000,
    ])->assertCreated();
});

it('lists only active products for a cashier', function () {
    [, $token] = productUser('kasir');
    Product::query()->create([
        'name' => 'Merah A',
        'price_retail' => 27500,
        'price_3kg' => 26000,
        'price_5kg' => 24000,
        'is_active' => true,
    ]);
    Product::query()->create([
        'name' => 'Merah B',
        'price_retail' => 25000,
        'price_3kg' => 24000,
        'price_5kg' => 22000,
        'is_active' => false,
    ]);

    $this->withToken($token)
        ->getJson('/api/v1/products')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Merah A');
});

it('allows an owner to change product activity', function () {
    [, $token] = productUser('owner');
    $product = Product::query()->create([
        'name' => 'Merah A',
        'price_retail' => 27500,
        'price_3kg' => 26000,
        'price_5kg' => 24000,
    ]);

    $this->withToken($token)
        ->patchJson("/api/v1/products/{$product->id}/active", ['is_active' => false])
        ->assertOk()
        ->assertJsonPath('data.is_active', false);

    expect(AuditLog::query()->where('action', 'product.status_changed')->exists())->toBeTrue();
});

it('rejects cashier catalog mutations and owner-only history', function () {
    [, $token] = productUser('kasir');
    $product = Product::query()->create([
        'name' => 'Merah A',
        'price_retail' => 27500,
        'price_3kg' => 26000,
        'price_5kg' => 24000,
    ]);

    $this->withToken($token)->postJson('/api/v1/products', [
        'name' => 'Putih A',
        'price_retail' => 30000,
        'price_3kg' => 28500,
        'price_5kg' => 26000,
    ])->assertForbidden();

    $this->withToken($token)->putJson("/api/v1/products/{$product->id}", [
        'name' => 'Merah A',
        'price_retail' => 28000,
        'price_3kg' => 26500,
        'price_5kg' => 24500,
    ])->assertForbidden();

    $this->withToken($token)->getJson("/api/v1/products/{$product->id}/price-history")->assertForbidden();
});
