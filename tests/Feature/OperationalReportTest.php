<?php

use App\Models\Product;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function operationalReportUser(string $role, string $suffix): array
{
    $user = User::factory()->create([
        'role' => $role,
        'username' => $role.'-operational-report-'.$suffix,
    ]);

    return [$user, $user->createToken('operational-report-test')->plainTextToken];
}

function operationalReportProduct(string $name, int $price): Product
{
    return Product::query()->create([
        'name' => $name,
        'jenis' => 'merah',
        'price_retail' => $price,
        'price_5kg' => $price,
    ]);
}

function operationalReportTransaction(
    User $cashier,
    Product $product,
    string $uuid,
    string $occurredAt,
    string $weight,
    int $total,
    int $cogs,
    bool $void = false
): Transaction {
    $transaction = Transaction::query()->create([
        'invoice_no' => 'POS-261022-'.substr($uuid, 0, 4),
        'client_uuid' => $uuid,
        'cashier_id' => $cashier->id,
        'customer_category' => 'ecer',
        'subtotal' => $total,
        'discount' => 0,
        'total' => $total,
        'amount_paid' => $total,
        'payment_method' => 'cash',
        'status' => $void ? 'void' : 'completed',
        'occurred_at' => $occurredAt,
        'void_reason' => $void ? 'Tes laporan' : null,
        'voided_by' => $void ? $cashier->id : null,
        'voided_at' => $void ? $occurredAt : null,
    ]);
    TransactionItem::query()->create([
        'transaction_id' => $transaction->id,
        'product_id' => $product->id,
        'product_name' => $product->name,
        'weight_kg' => $weight,
        'price_tier' => 'retail',
        'price_per_kg' => $total,
        'line_total' => $total,
        'cogs' => $cogs,
    ]);
    return $transaction;
}

it('reports sales by product for completed transactions and excludes void transactions', function () {
    [$owner, $token] = operationalReportUser('owner', 'sales');
    $cashier = User::factory()->create(['role' => 'kasir']);
    $red = operationalReportProduct('Merah A', 25000);
    $white = operationalReportProduct('Putih A', 30000);
    operationalReportTransaction($cashier, $red, '11111111-1111-4111-8111-111111111111', '2026-10-22 03:00:00', '4.000', 100000, 85000);
    operationalReportTransaction($cashier, $white, '22222222-2222-4222-8222-222222222222', '2026-10-22 04:00:00', '2.000', 60000, 40000);
    operationalReportTransaction($cashier, $red, '33333333-3333-4333-8333-333333333333', '2026-10-22 05:00:00', '1.000', 25000, 21000, true);

    $this->withToken($token)
        ->getJson('/api/v1/reports/sales-by-product?from=2026-10-22&to=2026-10-22')
        ->assertOk()
        ->assertJsonPath('data.0.product_name', 'Merah A')
        ->assertJsonPath('data.0.sold_weight_kg', '4.000')
        ->assertJsonPath('data.0.revenue', 100000)
        ->assertJsonPath('data.0.cogs', 85000)
        ->assertJsonPath('data.0.shortfall_count', 0)
        ->assertJsonPath('data.1.product_name', 'Putih A')
        ->assertJsonPath('data.1.revenue', 60000);
});

it('reports completed transaction counts, revenue, and void counts by cashier', function () {
    [$owner, $token] = operationalReportUser('owner', 'shifts');
    $morning = User::factory()->create(['role' => 'kasir', 'name' => 'Kasir Pagi']);
    $evening = User::factory()->create(['role' => 'kasir', 'name' => 'Kasir Sore']);
    $product = operationalReportProduct('Merah A', 25000);
    operationalReportTransaction($morning, $product, '44444444-4444-4444-8444-444444444444', '2026-10-22 03:00:00', '2.000', 50000, 40000);
    operationalReportTransaction($evening, $product, '55555555-5555-4555-8555-555555555555', '2026-10-22 04:00:00', '1.000', 25000, 20000);
    operationalReportTransaction($morning, $product, '66666666-6666-4666-8666-666666666666', '2026-10-22 05:00:00', '1.000', 25000, 20000, true);

    $this->withToken($token)
        ->getJson('/api/v1/reports/shifts?date=2026-10-22')
        ->assertOk()
        ->assertJsonPath('data.0.cashier_name', 'Kasir Pagi')
        ->assertJsonPath('data.0.transaction_count', 1)
        ->assertJsonPath('data.0.revenue', 50000)
        ->assertJsonPath('data.0.void_count', 1)
        ->assertJsonPath('data.1.cashier_name', 'Kasir Sore')
        ->assertJsonPath('data.1.transaction_count', 1)
        ->assertJsonPath('data.1.revenue', 25000);
});

it('protects operational reports from cashiers', function () {
    [, $cashierToken] = operationalReportUser('kasir', 'denied');

    $this->withToken($cashierToken)
        ->getJson('/api/v1/reports/stock')
        ->assertForbidden();
});
