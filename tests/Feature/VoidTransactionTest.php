<?php

use App\Models\AuditLog;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function voidUser(string $role, string $suffix): array
{
    $user = User::factory()->create([
        'role' => $role,
        'username' => $role.'-void-'.$suffix,
    ]);

    return [$user, $user->createToken('void-test')->plainTextToken];
}

function voidTransaction(User $cashier): Transaction
{
    $product = Product::query()->create([
        'name' => 'Merah A',
        'price_retail' => 27500,
        'price_3kg' => 26000,
        'price_5kg' => 24000,
    ]);

    $transaction = Transaction::query()->create([
        'invoice_no' => 'POS-261020-0001',
        'client_uuid' => fake()->uuid(),
        'cashier_id' => $cashier->id,
        'customer_category' => 'ecer',
        'subtotal' => 6900,
        'discount' => 0,
        'total' => 6900,
        'payment_method' => 'cash',
        'status' => 'completed',
        'occurred_at' => '2026-10-20 03:00:00',
    ]);

    $transaction->items()->create([
        'product_id' => $product->id,
        'product_name' => $product->name,
        'weight_kg' => '0.250',
        'price_tier' => 'retail',
        'price_per_kg' => 27500,
        'line_total' => 6900,
        'cogs' => 0,
    ]);

    return $transaction;
}

it('allows only an owner to void a transaction with a reason and audit log', function () {
    [$cashier, $cashierToken] = voidUser('kasir', 'cashier');
    $transaction = voidTransaction($cashier);

    $this->withToken($cashierToken)
        ->postJson("/api/v1/transactions/{$transaction->id}/void", ['reason' => 'Salah input'])
        ->assertForbidden();

    [$owner, $ownerToken] = voidUser('owner', 'owner');
    $this->app['auth']->forgetGuards();

    $this->withToken($ownerToken)
        ->postJson("/api/v1/transactions/{$transaction->id}/void", ['reason' => 'Salah input'])
        ->assertOk()
        ->assertJsonPath('data.status', 'void')
        ->assertJsonPath('data.void_reason', 'Salah input')
        ->assertJsonPath('data.voided_by', $owner->id)
        ->assertJsonPath('data.cogs', 0);

    $transaction->refresh();
    expect($transaction->status)->toBe('void')
        ->and($transaction->void_reason)->toBe('Salah input')
        ->and($transaction->voided_by)->toBe($owner->id)
        ->and($transaction->voided_at)->not->toBeNull();

    $audit = AuditLog::query()->where('action', 'transaction.void')->firstOrFail();
    expect($audit->user_id)->toBe($owner->id)
        ->and($audit->entity_type)->toBe(Transaction::class)
        ->and($audit->entity_id)->toBe($transaction->id)
        ->and($audit->payload)->toMatchArray(['reason' => 'Salah input']);
});

it('requires a non-empty reason before voiding', function () {
    [$cashier] = voidUser('kasir', 'validation-cashier');
    [, $ownerToken] = voidUser('owner', 'validation');
    $transaction = voidTransaction($cashier);

    $this->withToken($ownerToken)
        ->postJson("/api/v1/transactions/{$transaction->id}/void", ['reason' => ''])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['reason']);

    expect($transaction->fresh()->status)->toBe('completed')
        ->and(AuditLog::query()->count())->toBe(0);
});

it('rejects a second void with conflict and does not create another audit log', function () {
    [$cashier] = voidUser('kasir', 'repeat');
    [, $ownerToken] = voidUser('owner', 'repeat');
    $transaction = voidTransaction($cashier);

    $this->withToken($ownerToken)
        ->postJson("/api/v1/transactions/{$transaction->id}/void", ['reason' => 'Koreksi'])
        ->assertOk();

    $this->withToken($ownerToken)
        ->postJson("/api/v1/transactions/{$transaction->id}/void", ['reason' => 'Koreksi kedua'])
        ->assertStatus(409)
        ->assertJsonPath('message', 'Transaksi sudah di-void.');

    expect(AuditLog::query()->where('action', 'transaction.void')->count())->toBe(1);
});
