<?php

use App\Models\AuditLog;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function paymentRulesSetup(): array
{
    $cashier = User::factory()->create([
        'role' => 'kasir',
        'username' => 'payment-rules-cashier',
    ]);
    $product = Product::query()->create([
        'name' => 'Merah A',
        'price_retail' => 25000,
        'price_3kg' => 25000,
        'price_5kg' => 25000,
    ]);
    $supplier = Supplier::query()->create(['name' => 'Supplier Payment Rules']);
    $purchase = Purchase::query()->create([
        'supplier_id' => $supplier->id,
        'purchased_at' => '2026-10-24',
        'gross_weight_kg' => '3.000',
        'price_per_kg' => 10000,
        'total_cost' => 30000,
        'status' => 'finalized',
        'cost_per_clean_kg' => 10000,
        'clean_weight_kg' => '3.000',
        'finalized_at' => '2026-10-24 01:00:00',
        'created_by' => $cashier->id,
    ]);
    $lot = StockLot::query()->create([
        'purchase_id' => $purchase->id,
        'product_id' => $product->id,
        'clean_weight_kg' => '3.000',
        'unit_cost' => 10000,
        'cleaned_at' => '2026-10-24',
    ]);
    StockMovement::query()->create([
        'product_id' => $product->id,
        'lot_id' => $lot->id,
        'type' => 'in_clean',
        'weight_kg' => '3.000',
        'unit_cost' => 10000,
        'occurred_at' => '2026-10-24 01:00:00',
        'created_by' => $cashier->id,
    ]);

    return [$cashier, $cashier->createToken('payment-rules-test')->plainTextToken, $product];
}

function paymentRulesPayload(Product $product, array $overrides = []): array
{
    return array_replace([
        'client_uuid' => fake()->uuid(),
        'customer_category' => 'ecer',
        'items' => [['product_id' => $product->id, 'weight_kg' => '3.000']],
        'payment_method' => 'cash',
        'final_total' => 75000,
        'amount_paid' => 75000,
    ], $overrides);
}

function expectPaymentRejectionWithoutWrites($response, string $field): void
{
    $response->assertUnprocessable()->assertJsonValidationErrors($field);

    expect(Transaction::query()->count())->toBe(0)
        ->and(TransactionItem::query()->count())->toBe(0)
        ->and(StockMovement::query()->count())->toBe(1)
        ->and(StockMovement::query()->where('type', 'sale')->count())->toBe(0);
}

it('accepts T-01, T-02, T-03, T-11, T-13, and T-14 under Rule B', function (
    string $case,
    string $paymentMethod,
    ?int $finalTotal,
    int $amountPaid,
    int $expectedTotal,
    int $expectedDiscount
) {
    [, $token, $product] = paymentRulesSetup();
    $payload = paymentRulesPayload($product, [
        'payment_method' => $paymentMethod,
        'final_total' => $finalTotal,
        'amount_paid' => $amountPaid,
    ]);

    $response = $this->withToken($token)
        ->postJson('/api/v1/transactions', $payload)
        ->assertCreated()
        ->assertJsonPath('data.subtotal', 75000)
        ->assertJsonPath('data.total', $expectedTotal)
        ->assertJsonPath('data.discount', $expectedDiscount)
        ->assertJsonPath('data.amount_paid', $amountPaid);

    expect(Transaction::query()->count())->toBe(1)
        ->and(StockMovement::query()->where('type', 'sale')->count())->toBe(1);
})->with([
    'T-01' => ['T-01', 'cash', 75000, 75000, 75000, 0],
    'T-02' => ['T-02', 'cash', 75000, 100000, 75000, 0],
    'T-03' => ['T-03', 'cash', 70000, 70000, 70000, 5000],
    'T-11' => ['T-11', 'cash', 70000, 100000, 70000, 5000],
    'T-13' => ['T-13', 'qris', 75000, 75000, 75000, 0],
    'T-14' => ['T-14', 'qris', 70000, 70000, 70000, 5000],
]);

it('rejects T-04, T-05, T-06, T-10, and T-12 before transaction or stock writes under Rule B', function (
    string $case,
    string $paymentMethod,
    ?int $finalTotal,
    int $amountPaid,
    string $errorField
) {
    [, $token, $product] = paymentRulesSetup();
    $payload = paymentRulesPayload($product, [
        'payment_method' => $paymentMethod,
        'final_total' => $finalTotal,
        'amount_paid' => $amountPaid,
    ]);

    expectPaymentRejectionWithoutWrites(
        $this->withToken($token)->postJson('/api/v1/transactions', $payload),
        $errorField
    );
})->with([
    'T-04' => ['T-04', 'cash', 75000, 70000, 'amount_paid'],
    'T-05' => ['T-05', 'cash', 75000, 70000, 'amount_paid'],
    'T-06' => ['T-06', 'cash', 80000, 80000, 'final_total'],
    'T-10' => ['T-10', 'cash', 0, 100000, 'final_total'],
    'T-12' => ['T-12', 'qris', 75000, 80000, 'amount_paid'],
]);

it('writes T-21 discount audit payload only for a transaction with a discount', function () {
    [, $token, $product] = paymentRulesSetup();

    $this->withToken($token)
        ->postJson('/api/v1/transactions', paymentRulesPayload($product, [
            'client_uuid' => '21000000-0000-4000-8000-000000000001',
            'amount_paid' => 75000,
        ]))
        ->assertCreated();

    expect(AuditLog::query()->where('action', 'transaction.discount')->count())->toBe(0);

    $discounted = paymentRulesPayload($product, [
        'client_uuid' => '21000000-0000-4000-8000-000000000021',
        'final_total' => 70000,
        'amount_paid' => 70000,
    ]);
    $this->withToken($token)->postJson('/api/v1/transactions', $discounted)->assertCreated();

    $audit = AuditLog::query()->where('action', 'transaction.discount')->sole();
    expect($audit->payload)->toBe([
        'subtotal' => 75000,
        'final_total' => 70000,
        'amount_paid' => 70000,
        'discount' => 5000,
    ]);
});
