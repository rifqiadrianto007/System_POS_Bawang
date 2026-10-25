<?php

use App\Models\Product;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

function historyUser(string $role, string $suffix): array
{
    $user = User::factory()->create([
        'role' => $role,
        'username' => $role.'-'.$suffix,
    ]);

    return [$user, $user->createToken('history-test')->plainTextToken];
}

function historyTransaction(User $cashier, string $invoice, string $occurredAt, string $status = 'completed'): Transaction
{
    $transaction = Transaction::query()->create([
        'invoice_no' => $invoice,
        'client_uuid' => fake()->uuid(),
        'cashier_id' => $cashier->id,
        'customer_category' => 'ecer',
        'subtotal' => 6900,
        'discount' => 0,
        'total' => 6900,
        'amount_paid' => 6900,
        'payment_method' => 'cash',
        'status' => $status,
        'occurred_at' => $occurredAt,
    ]);

    $product = Product::query()->firstOrCreate(
        ['name' => 'Merah A'],
        ['price_retail' => 27500, 'price_3kg' => 26000, 'price_5kg' => 24000]
    );

    $transaction->items()->create([
        'product_id' => $product->id,
        'product_name' => 'Merah A',
        'weight_kg' => '0.250',
        'price_tier' => 'retail',
        'price_per_kg' => 27500,
        'line_total' => 6900,
        'cogs' => 0,
    ]);

    return $transaction;
}

it('limits a cashier to their own completed transactions from today in WIB', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-20 10:00:00', 'Asia/Jakarta'));
    [$cashier, $token] = historyUser('kasir', 'today');
    [$otherCashier] = historyUser('kasir', 'other');

    historyTransaction($cashier, 'POS-261020-0001', '2026-10-20 00:30:00+00:00');
    historyTransaction($cashier, 'POS-261019-0001', '2026-10-19 16:59:59+00:00');
    historyTransaction($otherCashier, 'POS-261020-0002', '2026-10-20 01:00:00+00:00');
    historyTransaction($cashier, 'POS-261020-0003', '2026-10-20 02:00:00+00:00', 'void');

    $this->withToken($token)
        ->getJson('/api/v1/transactions')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.invoice_no', 'POS-261020-0001')
        ->assertJsonMissingPath('data.0.cogs')
        ->assertJsonStructure(['data', 'meta']);

    Carbon::setTestNow();
});

it('allows an owner to view all transactions with filters and pagination', function () {
    [$owner, $token] = historyUser('owner', 'history');
    [$cashier] = historyUser('kasir', 'owner-filter');

    historyTransaction($cashier, 'POS-261019-0001', '2026-10-19 03:00:00+00:00');
    historyTransaction($cashier, 'POS-261020-0001', '2026-10-20 03:00:00+00:00', 'void');
    historyTransaction($owner, 'POS-261021-0001', '2026-10-21 03:00:00+00:00');

    $this->withToken($token)
        ->getJson('/api/v1/transactions?from=2026-10-19&to=2026-10-20&status=void&per_page=1')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.invoice_no', 'POS-261020-0001')
        ->assertJsonPath('meta.per_page', 1)
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.cogs', 0);
});

it('allows an owner to filter transactions by cashier', function () {
    [$owner, $token] = historyUser('owner', 'cashier-filter');
    [$cashierA] = historyUser('kasir', 'a');
    [$cashierB] = historyUser('kasir', 'b');

    historyTransaction($cashierA, 'POS-261020-0001', '2026-10-20 03:00:00+00:00');
    historyTransaction($cashierB, 'POS-261020-0002', '2026-10-20 04:00:00+00:00');

    $this->withToken($token)
        ->getJson('/api/v1/transactions?cashier_id='.$cashierA->id)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.cashier_id', $cashierA->id);
});

it('rejects invalid history filters and unauthenticated access', function () {
    $this->getJson('/api/v1/transactions')->assertUnauthorized();

    [$owner, $token] = historyUser('owner', 'invalid-filter');

    $this->withToken($token)
        ->getJson('/api/v1/transactions?status=pending&per_page=101')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['status', 'per_page']);
});
