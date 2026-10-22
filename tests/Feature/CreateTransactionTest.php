<?php

use App\Models\Product;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

function transactionUser(string $role = 'kasir'): array
{
    $user = User::factory()->create([
        'role' => $role,
        'username' => $role.'-transaction',
    ]);

    return [$user, $user->createToken('transaction-test')->plainTextToken];
}

function transactionProduct(): Product
{
    return Product::query()->create([
        'name' => 'Merah A',
        'price_retail' => 27500,
        'price_3kg' => 26000,
        'price_5kg' => 24000,
    ]);
}

function transactionPayload(int $productId, string $clientUuid = '2f9c6a52-5f55-4f4e-9d4a-0c5f9d1a7b11'): array
{
    return [
        'client_uuid' => $clientUuid,
        'customer_category' => 'ecer',
        'items' => [
            ['product_id' => $productId, 'weight_kg' => '0.500'],
            ['product_id' => $productId, 'weight_kg' => '1.000'],
        ],
        'customer_name' => 'Bu Sari',
        'payment_method' => 'cash',
        'final_total' => 40000,
        'occurred_at' => '2026-10-19T12:00:00+07:00',
    ];
}

it('creates a transaction with snapshots, discount, invoice, and stub cogs', function () {
    Carbon::setTestNow('2026-10-19 19:00:00', 'Asia/Jakarta');
    [$cashier, $token] = transactionUser();
    $product = transactionProduct();

    $response = $this->withToken($token)->postJson('/api/v1/transactions', transactionPayload($product->id));

    $response->assertCreated()
        ->assertJsonPath('data.invoice_no', 'POS-261019-0001')
        ->assertJsonPath('data.subtotal', 41300)
        ->assertJsonPath('data.discount', 1300)
        ->assertJsonPath('data.total', 40000)
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('data.items.0.product_name', 'Merah A')
        ->assertJsonPath('data.items.0.weight_kg', '1.500')
        ->assertJsonPath('data.items.0.price_per_kg', 27500)
        ->assertJsonPath('data.items.0.line_total', 41300)
        ->assertJsonMissingPath('data.items.0.cogs');

    $transaction = Transaction::query()->with('items')->firstOrFail();
    expect($transaction->cashier_id)->toBe($cashier->id)
        ->and($transaction->items)->toHaveCount(1)
        ->and($transaction->items->first()->product_name)->toBe('Merah A');

    Carbon::setTestNow();
});

it('returns the existing transaction for a repeated client uuid', function () {
    [$cashier, $token] = transactionUser();
    $product = transactionProduct();
    $payload = transactionPayload($product->id);

    $first = $this->withToken($token)->postJson('/api/v1/transactions', $payload)->assertCreated();
    $second = $this->withToken($token)->postJson('/api/v1/transactions', $payload)->assertOk();

    expect(Transaction::query()->count())->toBe(1)
        ->and($second->json('data.id'))->toBe($first->json('data.id'))
        ->and(Transaction::query()->first()->cashier_id)->toBe($cashier->id);
});

it('uses the next invoice number for the same Jakarta day', function () {
    [$user, $token] = transactionUser();
    $product = transactionProduct();

    $this->withToken($token)->postJson('/api/v1/transactions', transactionPayload($product->id));
    $secondPayload = transactionPayload($product->id, '3f9c6a52-5f55-4f4e-9d4a-0c5f9d1a7b12');
    $second = $this->withToken($token)->postJson('/api/v1/transactions', $secondPayload);

    $second->assertCreated()->assertJsonPath('data.invoice_no', 'POS-261019-0002');
    expect(Transaction::query()->where('cashier_id', $user->id)->count())->toBe(2);
});

it('allows owner and cashier but rejects unauthenticated requests', function () {
    $product = transactionProduct();

    $this->postJson('/api/v1/transactions', transactionPayload($product->id))
        ->assertUnauthorized();

    [, $ownerToken] = transactionUser('owner');
    $this->withToken($ownerToken)
        ->postJson('/api/v1/transactions', transactionPayload($product->id))
        ->assertCreated();
});

it('validates payment, uuid, and final total fields', function () {
    [, $token] = transactionUser();
    $product = transactionProduct();
    $payload = transactionPayload($product->id);
    unset($payload['client_uuid']);
    $payload['payment_method'] = 'transfer';

    $this->withToken($token)
        ->postJson('/api/v1/transactions', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['client_uuid', 'payment_method']);
});
