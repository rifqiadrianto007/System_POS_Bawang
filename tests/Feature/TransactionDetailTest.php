<?php

use App\Models\Product;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(fn () => Carbon::setTestNow(Carbon::parse('2026-10-26 12:00:00', 'Asia/Jakarta')));
afterEach(fn () => Carbon::setTestNow());

function transactionDetailUser(string $role, string $suffix): array
{
    $user = User::factory()->create([
        'role' => $role,
        'username' => $role.'-transaction-detail-'.$suffix,
    ]);

    return [$user, $user->createToken('transaction-detail-test')->plainTextToken];
}

function transactionDetailExample(User $cashier): Transaction
{
    $product = Product::query()->create([
        'name' => 'Merah A',
        'price_retail' => 27500,
        'price_3kg' => 26000,
        'price_5kg' => 24000,
    ]);

    $transaction = Transaction::query()->create([
        'invoice_no' => 'POS-261022-9001',
        'client_uuid' => '90000000-0000-4000-8000-000000000001',
        'cashier_id' => $cashier->id,
        'customer_category' => 'ecer',
        'customer_name' => 'Bu Sari',
        'subtotal' => 75000,
        'discount' => 5000,
        'total' => 70000,
        'amount_paid' => 70000,
        'payment_method' => 'cash',
        'status' => 'completed',
        'occurred_at' => '2026-10-26 03:00:00',
    ]);

    $transaction->items()->create([
        'product_id' => $product->id,
        'product_name' => 'Merah A',
        'weight_kg' => '3.000',
        'price_tier' => '3kg',
        'price_per_kg' => 25000,
        'line_total' => 75000,
        'cogs' => 63750,
    ]);

    return $transaction;
}

it('returns the documented discount example in transaction detail for its cashier', function () {
    [$cashier, $token] = transactionDetailUser('kasir', 'owner');
    $transaction = transactionDetailExample($cashier);

    $this->withToken($token)
        ->getJson("/api/v1/transactions/{$transaction->id}")
        ->assertOk()
        ->assertJsonPath('data.invoice_no', 'POS-261022-9001')
        ->assertJsonPath('data.subtotal', 75000)
        ->assertJsonPath('data.discount', 5000)
        ->assertJsonPath('data.total', 70000)
        ->assertJsonPath('data.items.0.weight_kg', '3.000')
        ->assertJsonPath('data.items.0.price_per_kg', 25000)
        ->assertJsonMissingPath('data.cogs')
        ->assertJsonMissingPath('data.items.0.cogs');
});

it('allows an owner to see transaction detail including cogs', function () {
    [$cashier] = transactionDetailUser('kasir', 'owner-access');
    [, $ownerToken] = transactionDetailUser('owner', 'owner-access');
    $transaction = transactionDetailExample($cashier);

    $this->withToken($ownerToken)
        ->getJson("/api/v1/transactions/{$transaction->id}")
        ->assertOk()
        ->assertJsonPath('data.total', 70000)
        ->assertJsonPath('data.cogs', 63750)
        ->assertJsonPath('data.items.0.cogs', 63750);
});

it('allows a cashier to view another cashier transaction detail within the shared history window', function () {
    [$cashier] = transactionDetailUser('kasir', 'first');
    [, $otherCashierToken] = transactionDetailUser('kasir', 'second');
    $transaction = transactionDetailExample($cashier);

    $this->withToken($otherCashierToken)
        ->getJson("/api/v1/transactions/{$transaction->id}")
        ->assertOk()
        ->assertJsonPath('data.invoice_no', 'POS-261022-9001');
});

it('requires authentication for transaction detail', function () {
    [$cashier] = transactionDetailUser('kasir', 'guest');
    $transaction = transactionDetailExample($cashier);

    $this->getJson("/api/v1/transactions/{$transaction->id}")
        ->assertUnauthorized();
});
