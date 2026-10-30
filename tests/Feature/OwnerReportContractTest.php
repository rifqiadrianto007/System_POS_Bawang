<?php

use App\Models\Expense;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\SaleAllocation;
use App\Models\StockCount;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

uses(RefreshDatabase::class);

afterEach(function (): void {
    Carbon::setTestNow();
});

function ownerReportUser(string $suffix, string $role = 'owner'): array
{
    $user = User::factory()->create([
        'role' => $role,
        'username' => $role.'-owner-report-'.$suffix,
    ]);

    return [$user, $user->createToken('owner-report-test')->plainTextToken];
}

function ownerReportProduct(string $name, bool $active = true): Product
{
    return Product::query()->create([
        'name' => $name,
        'price_retail' => 27500,
        'price_3kg' => 26000,
        'price_5kg' => 24000,
        'is_active' => $active,
    ]);
}

function ownerReportSale(
    User $cashier,
    Product $product,
    string $uuid,
    string $occurredAt,
    string $weight,
    int $subtotal,
    int $discount = 0,
    ?int $total = null,
    string $paymentMethod = 'cash',
    string $status = 'completed',
    int $cogs = 0,
    bool $shortfall = false
): Transaction {
    $total ??= $subtotal - $discount;
    $transaction = Transaction::query()->create([
        'invoice_no' => 'POS-'.strtoupper(str_replace('-', '', $uuid)),
        'client_uuid' => $uuid,
        'cashier_id' => $cashier->id,
        'customer_category' => 'ecer',
        'subtotal' => $subtotal,
        'discount' => $discount,
        'total' => $total,
        'amount_paid' => $total,
        'payment_method' => $paymentMethod,
        'status' => $status,
        'occurred_at' => $occurredAt,
        'void_reason' => $status === 'void' ? 'Tes report' : null,
        'voided_by' => $status === 'void' ? $cashier->id : null,
        'voided_at' => $status === 'void' ? $occurredAt : null,
    ]);
    $item = TransactionItem::query()->create([
        'transaction_id' => $transaction->id,
        'product_id' => $product->id,
        'product_name' => $product->name,
        'weight_kg' => $weight,
        'price_tier' => 'retail',
        'price_per_kg' => $subtotal,
        'line_total' => $subtotal,
        'cogs' => $cogs,
    ]);

    if ($shortfall) {
        SaleAllocation::query()->create([
            'transaction_item_id' => $item->id,
            'lot_id' => null,
            'weight_kg' => $weight,
            'unit_cost' => 0,
            'is_shortfall' => true,
        ]);
    }

    return $transaction;
}

function ownerReportPurchase(
    User $owner,
    Product $product,
    string $date,
    string $gross,
    string $clean,
    int $costPerKg,
    string $status = 'finalized',
    ?int $totalCostOverride = null
): Purchase {
    $supplier = Supplier::query()->create(['name' => 'Pemasok '.$date.' '.$product->name]);
    $totalCost = $totalCostOverride ?? (int) round(((int) $gross * $costPerKg));
    $purchase = Purchase::query()->create([
        'supplier_id' => $supplier->id,
        'purchased_at' => $date,
        'gross_weight_kg' => $gross,
        'price_per_kg' => $costPerKg,
        'total_cost' => $totalCost,
        'status' => $status,
        'cost_per_clean_kg' => $status === 'finalized' ? $costPerKg : null,
        'clean_weight_kg' => $status === 'finalized' ? $clean : null,
        'finalized_at' => $status === 'finalized' ? $date.' 00:00:00' : null,
        'created_by' => $owner->id,
    ]);

    if ($status === 'finalized' && trim($clean, '0.') !== '') {
        $lot = StockLot::query()->create([
            'purchase_id' => $purchase->id,
            'product_id' => $product->id,
            'clean_weight_kg' => $clean,
            'unit_cost' => $costPerKg,
            'cleaned_at' => $date,
        ]);
        StockMovement::query()->create([
            'product_id' => $product->id,
            'lot_id' => $lot->id,
            'type' => 'in_clean',
            'weight_kg' => $clean,
            'unit_cost' => $costPerKg,
            'occurred_at' => $date.' 01:00:00',
            'created_by' => $owner->id,
        ]);
    }

    return $purchase;
}

function ownerReportCount(User $owner, Product $product, string $countedAt, string $diff, int $lossValue): StockCount
{
    return StockCount::query()->create([
        'product_id' => $product->id,
        'counted_weight_kg' => '0.000',
        'system_weight_kg' => '0.000',
        'diff_kg' => $diff,
        'unit_cost_avg' => abs($lossValue),
        'loss_value' => $lossValue,
        'counted_at' => $countedAt,
        'counted_by' => $owner->id,
    ]);
}

function ownerReportExpense(User $owner, string $name, int $amount, string $date, string $category = 'lainnya'): Expense
{
    return Expense::query()->create([
        'name' => $name,
        'category' => $category,
        'amount' => $amount,
        'spent_at' => $date,
        'created_by' => $owner->id,
    ]);
}

it('LF-01 rejects mixed period and range, reversed ranges, and ranges longer than 366 days', function () {
    [, $token] = ownerReportUser('lf01');
    $this->withToken($token)->getJson('/api/v1/reports/finance?period=daily&date=2026-10-22&from=2026-10-01&to=2026-10-22')
        ->assertUnprocessable();
    $this->withToken($token)->getJson('/api/v1/reports/finance?from=2026-10-23&to=2026-10-22')
        ->assertUnprocessable()->assertJsonValidationErrors(['to']);
    $this->withToken($token)->getJson('/api/v1/reports/finance?from=2025-10-01&to=2026-10-02')
        ->assertUnprocessable()->assertJsonValidationErrors(['to']);
});

it('LF-02 returns every day in a seven-day range with zero-valued empty days in ascending order', function () {
    [$owner, $token] = ownerReportUser('lf02');
    $product = ownerReportProduct('LF02');
    ownerReportSale($owner, $product, 'f0020000-0000-4000-8000-000000000001', '2026-10-21 01:00:00', '1.000', 10000);
    ownerReportSale($owner, $product, 'f0020000-0000-4000-8000-000000000002', '2026-10-25 01:00:00', '1.000', 20000);

    $response = $this->withToken($token)->getJson('/api/v1/reports/finance?from=2026-10-20&to=2026-10-26')
        ->assertOk()->assertJsonCount(7, 'data.daily');
    $daily = $response->json('data.daily');
    expect(array_column($daily, 'date'))->toBe([
        '2026-10-20', '2026-10-21', '2026-10-22', '2026-10-23', '2026-10-24', '2026-10-25', '2026-10-26',
    ])->and($daily[0]['net_sales'])->toBe(0)->and($daily[2]['net_sales'])->toBe(0)->and($daily[5]['net_sales'])->toBe(20000);
});

it('LF-03 groups 05:30, 23:59, and next-day 00:00 sales by WIB dates', function () {
    [$owner, $token] = ownerReportUser('lf03');
    $product = ownerReportProduct('LF03');
    ownerReportSale($owner, $product, 'f0030000-0000-4000-8000-000000000001', '2026-10-25 22:30:00', '1.000', 30000);
    ownerReportSale($owner, $product, 'f0030000-0000-4000-8000-000000000002', '2026-10-26 16:59:00', '1.000', 40000);
    ownerReportSale($owner, $product, 'f0030000-0000-4000-8000-000000000003', '2026-10-26 17:00:00', '1.000', 90000);

    $this->withToken($token)->getJson('/api/v1/reports/finance?from=2026-10-26&to=2026-10-27')
        ->assertOk()->assertJsonPath('data.daily.0.net_sales', 70000)->assertJsonPath('data.daily.1.net_sales', 90000);
});

it('LF-04 attributes purchase, expense, and count values to their correct local calendar dates', function () {
    [$owner, $token] = ownerReportUser('lf04');
    $product = ownerReportProduct('LF04');
    ownerReportPurchase($owner, $product, '2026-10-22', '10.000', '8.000', 10000);
    ownerReportExpense($owner, 'Tanggal operasi', 6000, '2026-10-23');
    ownerReportCount($owner, $product, '2026-10-23 17:30:00', '-1.000', 5000); // 00:30 WIB on Oct 24

    $this->withToken($token)->getJson('/api/v1/reports/finance?period=daily&date=2026-10-22')->assertOk()
        ->assertJsonPath('data.daily.0.purchases_total', 100000)->assertJsonPath('data.cleaning_shrink_kg', '2.000')
        ->assertJsonPath('data.daily.0.operating_expenses', 0)->assertJsonPath('data.daily.0.display_shrink_value', 0);
    $this->withToken($token)->getJson('/api/v1/reports/finance?period=daily&date=2026-10-23')->assertOk()
        ->assertJsonPath('data.daily.0.operating_expenses', 6000)->assertJsonPath('data.daily.0.display_shrink_value', 0);
    $this->withToken($token)->getJson('/api/v1/reports/finance?period=daily&date=2026-10-24')->assertOk()
        ->assertJsonPath('data.daily.0.display_shrink_value', 5000);
});

it('LF-05 always returns cash and qris totals from completed transactions only', function () {
    [$owner, $token] = ownerReportUser('lf05');
    $product = ownerReportProduct('LF05');
    ownerReportSale($owner, $product, 'f0050000-0000-4000-8000-000000000001', '2026-10-22 01:00:00', '1.000', 100000, 0, null, 'cash');
    ownerReportSale($owner, $product, 'f0050000-0000-4000-8000-000000000002', '2026-10-22 02:00:00', '1.000', 70000, 5000, null, 'qris');
    ownerReportSale($owner, $product, 'f0050000-0000-4000-8000-000000000003', '2026-10-22 03:00:00', '1.000', 90000, 0, null, 'qris', 'void');

    $this->withToken($token)->getJson('/api/v1/reports/finance?period=daily&date=2026-10-22')->assertOk()
        ->assertJsonPath('data.by_payment_method.cash.count', 1)->assertJsonPath('data.by_payment_method.cash.total', 100000)
        ->assertJsonPath('data.by_payment_method.qris.count', 1)->assertJsonPath('data.by_payment_method.qris.total', 65000)
        ->assertJsonPath('data.net_sales', 165000)->assertJsonPath('data.void_count', 1);
});

it('LF-06 preserves the documented 143125 net profit, 1750000 spending, and -250000 cash flow', function () {
    [$owner, $token] = ownerReportUser('lf06');
    $product = ownerReportProduct('LF06');
    ownerReportPurchase($owner, $product, '2026-10-22', '100.000', '80.000', 21250, 'finalized', 1700000);
    ownerReportSale($owner, $product, 'f0060000-0000-4000-8000-000000000001', '2026-10-22 03:00:00', '60.000', 1500000, 0, 1500000, 'cash', 'completed', 1275000);
    ownerReportCount($owner, $product, '2026-10-22 05:00:00', '-1.500', 31875);
    ownerReportExpense($owner, 'Operasional contoh', 50000, '2026-10-22');

    $this->withToken($token)->getJson('/api/v1/reports/finance?period=monthly&month=2026-10')->assertOk()
        ->assertJsonPath('data.net_profit', 143125)->assertJsonPath('data.total_spending', 1750000)
        ->assertJsonPath('data.cash_flow', -250000);
});

it('LF-07 keeps net profit distinct from net sales minus total spending', function () {
    [$owner, $token] = ownerReportUser('lf07');
    $product = ownerReportProduct('LF07');
    ownerReportPurchase($owner, $product, '2026-10-22', '100.000', '80.000', 21250);
    ownerReportSale($owner, $product, 'f0070000-0000-4000-8000-000000000001', '2026-10-22 03:00:00', '60.000', 1500000, 0, 1500000, 'cash', 'completed', 1275000);
    ownerReportCount($owner, $product, '2026-10-22 05:00:00', '-1.500', 31875);
    ownerReportExpense($owner, 'Operasional contoh', 50000, '2026-10-22');
    $report = $this->withToken($token)->getJson('/api/v1/reports/finance?period=monthly&month=2026-10')->assertOk()->json('data');

    expect($report['net_profit'])->toBe(143125)->and($report['net_profit'])->not->toBe($report['net_sales'] - $report['total_spending']);
});

it('LF-08 reconciles top-level totals with daily totals and independent database aggregates', function () {
    [$owner, $token] = ownerReportUser('lf08');
    $product = ownerReportProduct('LF08');
    ownerReportSale($owner, $product, 'f0080000-0000-4000-8000-000000000001', '2026-10-21 01:00:00', '1.000', 100000, 5000, 95000);
    ownerReportSale($owner, $product, 'f0080000-0000-4000-8000-000000000002', '2026-10-22 02:00:00', '1.000', 200000, 0, 200000, 'qris');
    ownerReportExpense($owner, 'Biaya A', 10000, '2026-10-21');
    ownerReportPurchase($owner, $product, '2026-10-22', '10.000', '8.000', 10000, 'open');

    $data = $this->withToken($token)->getJson('/api/v1/reports/finance?from=2026-10-21&to=2026-10-22')->assertOk()->json('data');
    $daily = collect($data['daily']);
    expect($data['net_sales'])->toBe((int) Transaction::query()->where('status', 'completed')->sum('total'))
        ->and($data['subtotal_sum'])->toBe((int) Transaction::query()->where('status', 'completed')->sum('subtotal'))
        ->and($data['discount_sum'])->toBe((int) Transaction::query()->where('status', 'completed')->sum('discount'))
        ->and($data['cogs'])->toBe((int) TransactionItem::query()->sum('cogs'))
        ->and($data['operating_expenses'])->toBe((int) Expense::query()->sum('amount'))
        ->and($data['purchases_total'])->toBe((int) Purchase::query()->sum('total_cost'))
        ->and($data['net_sales'])->toBe($daily->sum('net_sales'))
        ->and($data['cogs'])->toBe($daily->sum('cogs'))
        ->and($data['operating_expenses'])->toBe($daily->sum('operating_expenses'))
        ->and($data['purchases_total'])->toBe($daily->sum('purchases_total'));
});

it('LF-09 excludes void transactions and soft-deleted expenses from totals and daily rows', function () {
    [$owner, $token] = ownerReportUser('lf09');
    $product = ownerReportProduct('LF09');
    ownerReportSale($owner, $product, 'f0090000-0000-4000-8000-000000000001', '2026-10-22 01:00:00', '1.000', 100000, 0, null, 'cash', 'completed', 80000);
    ownerReportSale($owner, $product, 'f0090000-0000-4000-8000-000000000002', '2026-10-22 02:00:00', '1.000', 90000, 0, null, 'cash', 'void', 50000);
    $activeExpense = ownerReportExpense($owner, 'Aktif', 5000, '2026-10-22');
    $deletedExpense = ownerReportExpense($owner, 'Terhapus', 7000, '2026-10-22');
    $deletedExpense->deleted_by = $owner->id;
    $deletedExpense->save();
    $deletedExpense->delete();

    $this->withToken($token)->getJson('/api/v1/reports/finance?period=daily&date=2026-10-22')->assertOk()
        ->assertJsonPath('data.net_sales', 100000)->assertJsonPath('data.cogs', 80000)
        ->assertJsonPath('data.operating_expenses', 5000)->assertJsonPath('data.daily.0.operating_expenses', 5000)
        ->assertJsonPath('data.void_count', 1);
});

it('LF-10 returns correct discount, void, shortfall, and cleaning shrink fields', function () {
    [$owner, $token] = ownerReportUser('lf10');
    $product = ownerReportProduct('LF10');
    ownerReportPurchase($owner, $product, '2026-10-22', '10.000', '8.000', 10000);
    ownerReportSale($owner, $product, 'f0100000-0000-4000-8000-000000000001', '2026-10-22 01:00:00', '1.000', 75000, 5000, 70000, 'cash', 'completed', 50000, true);
    ownerReportSale($owner, $product, 'f0100000-0000-4000-8000-000000000002', '2026-10-22 02:00:00', '1.000', 20000, 0, null, 'cash', 'void');

    $this->withToken($token)->getJson('/api/v1/reports/finance?period=daily&date=2026-10-22')->assertOk()
        ->assertJsonPath('data.discount_sum', 5000)->assertJsonPath('data.void_count', 1)
        ->assertJsonPath('data.shortfall_count', 1)->assertJsonPath('data.cleaning_shrink_kg', '2.000');
});

it('LF-11 returns integer money fields and zero values for an empty period', function () {
    [$owner, $token] = ownerReportUser('lf11');
    $data = $this->withToken($token)->getJson('/api/v1/reports/finance?period=daily&date=2026-10-22')->assertOk()->json('data');
    $moneyFields = [
        'net_sales', 'cogs', 'display_shrink_value', 'operating_expenses', 'net_profit', 'purchases_total', 'cash_flow',
        'transactions_count', 'subtotal_sum', 'discount_sum', 'gross_profit', 'total_spending',
    ];
    foreach ($moneyFields as $field) {
        expect($data[$field])->toBeInt()->toBe(0);
    }
    expect($data['cleaning_shrink_kg'])->toBe('0.000')
        ->and($data['by_payment_method'])->toBe(['cash' => ['count' => 0, 'total' => 0], 'qris' => ['count' => 0, 'total' => 0]])
        ->and($data['daily'])->toHaveCount(1);
});

it('LF-12 restricts finance reports to authenticated owners', function () {
    [, $cashierToken] = ownerReportUser('lf12-cashier', 'kasir');
    $this->withToken($cashierToken)->getJson('/api/v1/reports/finance')->assertForbidden();
    Auth::forgetGuards();
    $this->withHeaders(['Authorization' => ''])->getJson('/api/v1/reports/finance')->assertUnauthorized();
});

it('LS-01 separates open incoming gross from finalized clean stock and shrink', function () {
    [$owner, $token] = ownerReportUser('ls01');
    $product = ownerReportProduct('LS01');
    ownerReportPurchase($owner, $product, '2026-10-22', '100.000', '80.000', 10000);
    ownerReportPurchase($owner, $product, '2026-10-23', '50.000', '0.000', 10000, 'open');

    $this->withToken($token)->getJson('/api/v1/reports/stock?from=2026-10-22&to=2026-10-23')->assertOk()
        ->assertJsonPath('data.incoming_gross_kg', '150.000')->assertJsonPath('data.pending_gross_kg', '50.000')
        ->assertJsonPath('data.clean_kg', '80.000')->assertJsonPath('data.cleaning_shrink_kg', '20.000');
});

it('LS-02 signs display shrink kilograms and values for both shrink and positive corrections', function () {
    [$owner, $token] = ownerReportUser('ls02');
    $product = ownerReportProduct('LS02');
    ownerReportPurchase($owner, $product, '2026-10-22', '10.000', '8.000', 10000);
    ownerReportCount($owner, $product, '2026-10-22 01:00:00', '-1.500', 31875);
    ownerReportCount($owner, $product, '2026-10-23 01:00:00', '0.500', -5000);

    $this->withToken($token)->getJson('/api/v1/reports/stock?from=2026-10-22&to=2026-10-23')->assertOk()
        ->assertJsonPath('data.display_shrink_kg', '-1.000')->assertJsonPath('data.display_shrink_value', 26875);
});

it('LS-03 includes inactive products with string stock weights and integer values', function () {
    [$owner, $token] = ownerReportUser('ls03');
    $active = ownerReportProduct('LS03 aktif');
    $inactive = ownerReportProduct('LS03 nonaktif', false);
    ownerReportPurchase($owner, $active, '2026-10-22', '10.000', '8.000', 10000);

    $response = $this->withToken($token)->getJson('/api/v1/reports/stock?from=2026-10-22&to=2026-10-22')->assertOk();
    $rows = collect($response->json('data.products'));
    expect($rows->pluck('name')->all())->toBe(['LS03 aktif', 'LS03 nonaktif'])
        ->and($rows->firstWhere('product_id', $inactive->id)['stock_kg'])->toBe('0.000')
        ->and($rows->firstWhere('product_id', $inactive->id)['stock_value'])->toBeInt()
        ->and($rows->firstWhere('product_id', $active->id)['stock_kg'])->toBe('8.000');
});

it('LS-04 rounds inventory value separately for each remaining lot', function () {
    [$owner, $token] = ownerReportUser('ls04');
    $product = ownerReportProduct('LS04');
    ownerReportPurchase($owner, $product, '2026-10-21', '1.000', '0.001', 500);
    ownerReportPurchase($owner, $product, '2026-10-22', '1.000', '0.001', 500);

    $this->withToken($token)->getJson('/api/v1/reports/stock?from=2026-10-22&to=2026-10-22')->assertOk()
        ->assertJsonPath('data.stock_ready_kg', '0.002')->assertJsonPath('data.stock_value', 2)
        ->assertJsonPath('data.products.0.stock_value', 2);
});

it('LS-05 counts sold weight from completed transactions and excludes void transactions', function () {
    [$owner, $token] = ownerReportUser('ls05');
    $product = ownerReportProduct('LS05');
    ownerReportSale($owner, $product, 'f1050000-0000-4000-8000-000000000001', '2026-10-22 01:00:00', '3.250', 100000);
    ownerReportSale($owner, $product, 'f1050000-0000-4000-8000-000000000002', '2026-10-22 02:00:00', '9.000', 200000, 0, null, 'cash', 'void');
    $this->withToken($token)->getJson('/api/v1/reports/stock?from=2026-10-22&to=2026-10-22')->assertOk()
        ->assertJsonPath('data.sold_kg', '3.250')->assertJsonPath('data.products.0.sold_kg', '3.250');
});

it('LS-06 keeps current stock and stock value independent of the selected report range', function () {
    [$owner, $token] = ownerReportUser('ls06');
    $product = ownerReportProduct('LS06');
    ownerReportPurchase($owner, $product, '2026-10-22', '10.000', '8.000', 12500);
    $first = $this->withToken($token)->getJson('/api/v1/reports/stock?from=2026-10-01&to=2026-10-22')->assertOk()->json('data');
    $second = $this->withToken($token)->getJson('/api/v1/reports/stock?from=2026-11-01&to=2026-11-30')->assertOk()->json('data');

    expect($first['stock_ready_kg'])->toBe($second['stock_ready_kg'])->toBe('8.000')
        ->and($first['stock_value'])->toBe($second['stock_value'])->toBe(100000);
});

it('LS-07 runs a constant number of report queries for five and thirty products', function () {
    [$owner, $token] = ownerReportUser('ls07');
    foreach (range(1, 5) as $number) {
        ownerReportProduct('LS07 '.str_pad((string) $number, 2, '0', STR_PAD_LEFT));
    }
    $countRelevantQueries = false;
    $queryCount = 0;
    $reportTables = ['transactions', 'transaction_items', 'purchases', 'expenses', 'stock_counts', 'products', 'stock_movements', 'stock_lots'];
    DB::listen(function (QueryExecuted $query) use (&$countRelevantQueries, &$queryCount, $reportTables): void {
        if ($countRelevantQueries && collect($reportTables)->contains(fn (string $table): bool => str_contains(strtolower($query->sql), $table))) {
            $queryCount++;
        }
    });
    $this->withToken($token)->getJson('/api/v1/reports/stock?from=2026-10-01&to=2026-10-31')->assertOk();
    $this->app['auth']->forgetGuards();
    $countRelevantQueries = true;
    $this->withToken($token)->getJson('/api/v1/reports/stock?from=2026-10-01&to=2026-10-31')->assertOk();
    $fiveProductQueries = $queryCount;

    $countRelevantQueries = false;
    foreach (range(6, 30) as $number) {
        ownerReportProduct('LS07 '.str_pad((string) $number, 2, '0', STR_PAD_LEFT));
    }
    $queryCount = 0;
    $this->app['auth']->forgetGuards();
    $countRelevantQueries = true;
    $this->withToken($token)->getJson('/api/v1/reports/stock?from=2026-10-01&to=2026-10-31')->assertOk();
    $thirtyProductQueries = $queryCount;

    expect($fiveProductQueries)->toBe($thirtyProductQueries);
});
