<?php

use App\Models\Product;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

function amountPaidPresentationUser(string $role, string $suffix): array
{
    $user = User::factory()->create([
        'role' => $role,
        'username' => $role.'-amount-presentation-'.$suffix,
    ]);

    return [$user, $user->createToken('amount-presentation-test')->plainTextToken];
}

function amountPaidPresentationTransaction(User $cashier, array $overrides = []): Transaction
{
    $defaults = [
        'invoice_no' => 'POS-261024-'.str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT),
        'client_uuid' => fake()->uuid(),
        'cashier_id' => $cashier->id,
        'customer_category' => 'ecer',
        'subtotal' => 75000,
        'discount' => 0,
        'total' => 75000,
        'amount_paid' => 75000,
        'payment_method' => 'cash',
        'status' => 'completed',
        'occurred_at' => '2026-10-24 03:00:00',
    ];

    return Transaction::query()->create(array_replace($defaults, $overrides));
}

function amountPaidPresentationItem(Transaction $transaction): void
{
    $product = Product::query()->create([
        'name' => 'Produk regression '.$transaction->id,
        'price_retail' => 27500,
        'price_3kg' => 26000,
        'price_5kg' => 24000,
    ]);

    TransactionItem::query()->create([
        'transaction_id' => $transaction->id,
        'product_id' => $product->id,
        'product_name' => $product->name,
        'weight_kg' => '2.727',
        'price_tier' => 'retail',
        'price_per_kg' => 27500,
        'line_total' => $transaction->subtotal,
        'cogs' => 50000,
    ]);
}

it('returns null payment and change for legacy transactions in detail and receipt', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-27 10:00:00', 'Asia/Jakarta'));
    [$cashier, $token] = amountPaidPresentationUser('kasir', 'legacy');
    $transaction = amountPaidPresentationTransaction($cashier, ['amount_paid' => null, 'occurred_at' => '2026-10-26 03:00:00']);
    amountPaidPresentationItem($transaction);

    $this->withToken($token)
        ->getJson("/api/v1/transactions/{$transaction->id}")
        ->assertOk()
        ->assertJsonPath('data.amount_paid', null)
        ->assertJsonPath('data.change', null);

    $this->withToken($token)
        ->getJson("/api/v1/transactions/{$transaction->id}/receipt")
        ->assertOk()
        ->assertJsonPath('data.amount_paid', null)
        ->assertJsonPath('data.change', null);
});

it('includes payment and computed change in a discounted overpaid receipt', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-27 10:00:00', 'Asia/Jakarta'));
    [$cashier, $token] = amountPaidPresentationUser('kasir', 'receipt');
    $transaction = amountPaidPresentationTransaction($cashier, [
        'discount' => 5000,
        'total' => 70000,
        'amount_paid' => 100000,
        'occurred_at' => '2026-10-26 03:00:00',
    ]);
    amountPaidPresentationItem($transaction);

    $this->withToken($token)
        ->getJson("/api/v1/transactions/{$transaction->id}/receipt")
        ->assertOk()
        ->assertJsonPath('data.amount_paid', 100000)
        ->assertJsonPath('data.total', 70000)
        ->assertJsonPath('data.discount', 5000)
        ->assertJsonPath('data.change', 30000);
});

it('shows payment details to cashiers without exposing cogs in detail and receipt', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-27 10:00:00', 'Asia/Jakarta'));
    [$cashier, $token] = amountPaidPresentationUser('kasir', 'role');
    $transaction = amountPaidPresentationTransaction($cashier, ['amount_paid' => 100000, 'occurred_at' => '2026-10-26 03:00:00']);
    amountPaidPresentationItem($transaction);

    $this->withToken($token)
        ->getJson("/api/v1/transactions/{$transaction->id}")
        ->assertOk()
        ->assertJsonPath('data.amount_paid', 100000)
        ->assertJsonPath('data.change', 25000)
        ->assertJsonMissingPath('data.cogs')
        ->assertJsonMissingPath('data.items.0.cogs');

    $this->withToken($token)
        ->getJson("/api/v1/transactions/{$transaction->id}/receipt")
        ->assertOk()
        ->assertJsonPath('data.amount_paid', 100000)
        ->assertJsonPath('data.change', 25000)
        ->assertJsonMissingPath('data.cogs')
        ->assertJsonMissingPath('data.items.0.cogs');
});

it('keeps transaction history rows to the documented cashier list fields', function () {
    [$cashier, $token] = amountPaidPresentationUser('kasir', 'history');
    Carbon::setTestNow(Carbon::parse('2026-10-24 10:00:00', 'Asia/Jakarta'));
    $transaction = amountPaidPresentationTransaction($cashier, ['amount_paid' => 100000]);

    $history = $this->withToken($token)
        ->getJson('/api/v1/transactions')
        ->assertOk()
        ->assertJsonMissingPath('data.0.amount_paid')
        ->assertJsonMissingPath('data.0.change')
        ->assertJsonMissingPath('data.0.cogs');

    // R4 H-25 defines an exact list row shape; payment fields remain on detail and receipt.
    expect(array_keys($history->json('data.0')))->toBe([
        'id', 'invoice_no', 'occurred_at', 'cashier_name', 'items_count', 'total_weight_kg', 'subtotal', 'discount', 'total', 'payment_method', 'status', 'customer_name',
    ]);

    Carbon::setTestNow();
});

it('uses transaction total rather than amount_paid for net sales', function () {
    [$owner, $token] = amountPaidPresentationUser('owner', 'report');
    $transaction = amountPaidPresentationTransaction($owner, [
        'invoice_no' => 'POS-261024-9001',
        'amount_paid' => 100000,
    ]);
    amountPaidPresentationItem($transaction);

    $this->withToken($token)
        ->getJson('/api/v1/reports/finance?period=daily&date=2026-10-24')
        ->assertOk()
        ->assertJsonPath('data.net_sales', 75000);
});

it('keeps amount_paid when voiding and excludes the voided total from reports', function () {
    [$owner, $token] = amountPaidPresentationUser('owner', 'void');
    $transaction = amountPaidPresentationTransaction($owner, [
        'invoice_no' => 'POS-261024-9002',
        'amount_paid' => 100000,
    ]);
    amountPaidPresentationItem($transaction);

    $this->withToken($token)
        ->postJson("/api/v1/transactions/{$transaction->id}/void", ['reason' => 'Regression void'])
        ->assertOk();

    expect($transaction->fresh()->amount_paid)->toBe(100000)
        ->and($transaction->fresh()->status)->toBe('void');

    $this->withToken($token)
        ->getJson('/api/v1/reports/finance?period=daily&date=2026-10-24')
        ->assertOk()
        ->assertJsonPath('data.net_sales', 0)
        ->assertJsonPath('data.void_count', 1);
});
