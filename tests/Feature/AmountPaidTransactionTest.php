<?php

use App\Models\Product;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

function amountPaidCashier(string $suffix = 'default'): array
{
    $cashier = User::factory()->create([
        'role' => 'kasir',
        'username' => 'amount-paid-'.$suffix,
    ]);

    return [$cashier, $cashier->createToken('amount-paid-test')->plainTextToken];
}

function amountPaidProduct(): Product
{
    return Product::query()->create([
        'name' => 'Merah A',
        'price_retail' => 27500,
        'price_3kg' => 26000,
        'price_5kg' => 24000,
    ]);
}

function amountPaidPayload(Product $product, string $uuid, array $overrides = []): array
{
    return array_replace([
        'client_uuid' => $uuid,
        'customer_category' => 'ecer',
        'items' => [
            ['product_id' => $product->id, 'weight_kg' => '0.250'],
        ],
        'payment_method' => 'cash',
        'final_total' => null,
        'amount_paid' => 7500,
    ], $overrides);
}

it('rejects amount_paid zero with the documented minimum validation', function () {
    [, $token] = amountPaidCashier('minimum');
    $product = amountPaidProduct();

    $this->withToken($token)
        ->postJson('/api/v1/transactions', amountPaidPayload(
            $product,
            '70000000-0000-4000-8000-000000000007',
            ['amount_paid' => 0]
        ))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('amount_paid')
        ->assertJsonPath('errors.amount_paid.0', 'Uang yang dibayarkan minimal Rp1.');
});

it('requires amount_paid', function () {
    [, $token] = amountPaidCashier('required');
    $product = amountPaidProduct();
    $payload = amountPaidPayload($product, '80000000-0000-4000-8000-000000000008');
    unset($payload['amount_paid']);

    $this->withToken($token)
        ->postJson('/api/v1/transactions', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('amount_paid')
        ->assertJsonPath('errors.amount_paid.0', 'Uang yang dibayarkan wajib diisi.');
});

it('rejects non-integer amount_paid values', function (mixed $amountPaid) {
    [, $token] = amountPaidCashier('integer-'.md5(serialize($amountPaid)));
    $product = amountPaidProduct();

    $this->withToken($token)
        ->postJson('/api/v1/transactions', amountPaidPayload(
            $product,
            fake()->uuid(),
            ['amount_paid' => $amountPaid]
        ))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('amount_paid')
        ->assertJsonPath('errors.amount_paid.0', 'Uang yang dibayarkan harus berupa bilangan bulat rupiah.');
})->with([
    'text' => ['abc'],
    'fractional number' => [100000.5],
]);

it('rejects amount_paid above one billion rupiah', function () {
    [, $token] = amountPaidCashier('maximum');
    $product = amountPaidProduct();

    $this->withToken($token)
        ->postJson('/api/v1/transactions', amountPaidPayload(
            $product,
            '10000000-0000-4000-8000-000000000010',
            ['amount_paid' => 1000000001]
        ))
        ->assertUnprocessable()
        ->assertJsonPath('errors.amount_paid.0', 'Uang yang dibayarkan tidak boleh melebihi Rp1.000.000.000.');
});

it('persists amount_paid and returns it in transaction detail', function () {
    [, $token] = amountPaidCashier('persist');
    $product = amountPaidProduct();
    $payload = amountPaidPayload($product, '15000000-0000-4000-8000-000000000015', [
        'amount_paid' => 100000,
    ]);

    $created = $this->withToken($token)
        ->postJson('/api/v1/transactions', $payload)
        ->assertCreated();
    $transactionId = $created->json('data.id');

    expect(Transaction::query()->findOrFail($transactionId)->amount_paid)->toBe(100000);

    $this->withToken($token)
        ->getJson("/api/v1/transactions/{$transactionId}")
        ->assertOk()
        ->assertJsonPath('data.amount_paid', 100000);
});

it('keeps the first amount_paid when a client_uuid is retried with a different amount', function () {
    [, $token] = amountPaidCashier('idempotent');
    $product = amountPaidProduct();
    $uuid = '20000000-0000-4000-8000-000000000020';
    $firstPayload = amountPaidPayload($product, $uuid, ['amount_paid' => 100000]);
    $secondPayload = amountPaidPayload($product, $uuid, ['amount_paid' => 120000]);

    $first = $this->withToken($token)->postJson('/api/v1/transactions', $firstPayload)->assertCreated();
    $second = $this->withToken($token)->postJson('/api/v1/transactions', $secondPayload)->assertOk();

    expect(Transaction::query()->where('client_uuid', $uuid)->count())->toBe(1)
        ->and(Transaction::query()->where('client_uuid', $uuid)->value('amount_paid'))->toBe(100000)
        ->and($second->json('data.id'))->toBe($first->json('data.id'))
        ->and($second->json('data.amount_paid'))->toBe(100000);
});

it('supports fresh migration and rollback of the nullable amount_paid column', function () {
    // RefreshDatabase has already run migrate:fresh for this test connection.
    expect(Schema::hasColumn('transactions', 'amount_paid'))->toBeTrue();

    expect(Artisan::call('migrate:rollback', ['--step' => 1, '--force' => true]))->toBe(0)
        ->and(Schema::hasColumn('transactions', 'amount_paid'))->toBeFalse();

    expect(Artisan::call('migrate', ['--force' => true]))->toBe(0)
        ->and(Schema::hasColumn('transactions', 'amount_paid'))->toBeTrue();
});
