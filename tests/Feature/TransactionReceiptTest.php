<?php

use App\Models\Product;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function receiptUser(string $role, string $suffix): array
{
    $user = User::factory()->create([
        'role' => $role,
        'username' => $role.'-receipt-'.$suffix,
    ]);

    return [$user, $user->createToken('receipt-test')->plainTextToken];
}

function receiptTransaction(User $cashier, string $invoice = 'POS-261020-0001'): Transaction
{
    $product = Product::query()->create([
        'name' => 'Merah A',
        'price_retail' => 27500,
        'price_3kg' => 26000,
        'price_5kg' => 24000,
    ]);

    $transaction = Transaction::query()->create([
        'invoice_no' => $invoice,
        'client_uuid' => fake()->uuid(),
        'cashier_id' => $cashier->id,
        'customer_category' => 'ecer',
        'customer_name' => 'Bu Sari',
        'subtotal' => 41300,
        'discount' => 1300,
        'total' => 40000,
        'payment_method' => 'cash',
        'status' => 'completed',
        'occurred_at' => '2026-10-20 03:00:00',
    ]);

    $transaction->items()->create([
        'product_id' => $product->id,
        'product_name' => $product->name,
        'weight_kg' => '1.500',
        'price_tier' => 'retail',
        'price_per_kg' => 27500,
        'line_total' => 41300,
        'cogs' => 0,
    ]);

    return $transaction;
}

it('returns structured receipt data for the cashier who owns the transaction', function () {
    [$cashier, $token] = receiptUser('kasir', 'owner');
    $transaction = receiptTransaction($cashier);

    $this->withToken($token)
        ->getJson("/api/v1/transactions/{$transaction->id}/receipt")
        ->assertOk()
        ->assertJsonPath('data.invoice_no', 'POS-261020-0001')
        ->assertJsonPath('data.customer_name', 'Bu Sari')
        ->assertJsonPath('data.items.0.product_name', 'Merah A')
        ->assertJsonPath('data.items.0.weight_kg', '1.500')
        ->assertJsonPath('data.items.0.price_per_kg', 27500)
        ->assertJsonPath('data.items.0.line_total', 41300)
        ->assertJsonPath('data.subtotal', 41300)
        ->assertJsonPath('data.discount', 1300)
        ->assertJsonPath('data.total', 40000)
        ->assertJsonPath('data.payment_method', 'cash')
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonMissingPath('data.cogs');
});

it('renders a digital receipt with the documented discount example for browser printing', function () {
    [$cashier, $token] = receiptUser('kasir', 'print');
    $transaction = receiptTransaction($cashier, 'POS-261020-0005');
    $transaction->forceFill([
        'subtotal' => 75000,
        'discount' => 5000,
        'total' => 70000,
    ])->save();
    $transaction->items()->first()->forceFill([
        'weight_kg' => '3.000',
        'price_tier' => '3kg',
        'price_per_kg' => 25000,
        'line_total' => 75000,
    ])->save();

    $this->withToken($token)
        ->get("/api/v1/transactions/{$transaction->id}/receipt/print")
        ->assertOk()
        ->assertHeader('content-type', 'text/html; charset=UTF-8')
        ->assertSeeText('POS-261020-0005')
        ->assertSeeText('Merah A')
        ->assertSeeText('3.000 kg')
        ->assertSeeText('Rp75.000')
        ->assertSeeText('Diskon')
        ->assertSeeText('Rp5.000')
        ->assertSeeText('Rp70.000')
        ->assertSee('window.print()', false)
        ->assertDontSeeText('COGS');
});

it('allows the owner to retrieve any transaction receipt', function () {
    [$cashier] = receiptUser('kasir', 'owner-access');
    [, $ownerToken] = receiptUser('owner', 'owner-access');
    $transaction = receiptTransaction($cashier, 'POS-261020-0002');

    $this->withToken($ownerToken)
        ->getJson("/api/v1/transactions/{$transaction->id}/receipt")
        ->assertOk()
        ->assertJsonPath('data.invoice_no', 'POS-261020-0002')
        ->assertJsonPath('data.cogs', 0);
});

it('denies a cashier access to another cashier receipt', function () {
    [$cashier] = receiptUser('kasir', 'first');
    [, $otherToken] = receiptUser('kasir', 'second');
    $transaction = receiptTransaction($cashier, 'POS-261020-0003');

    $this->withToken($otherToken)
        ->getJson("/api/v1/transactions/{$transaction->id}/receipt")
        ->assertForbidden();

    $this->withToken($otherToken)
        ->get("/api/v1/transactions/{$transaction->id}/receipt/print")
        ->assertForbidden();
});

it('requires authentication for a transaction receipt', function () {
    [$cashier] = receiptUser('kasir', 'guest');
    $transaction = receiptTransaction($cashier, 'POS-261020-0004');

    $this->getJson("/api/v1/transactions/{$transaction->id}/receipt")
        ->assertUnauthorized();
});
