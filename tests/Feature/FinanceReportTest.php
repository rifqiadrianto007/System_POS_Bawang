<?php

use App\Models\Expense;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\StockCount;
use App\Models\Supplier;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Filesystem\Filesystem;

uses(RefreshDatabase::class);

function reportUser(string $role, string $suffix): array
{
    $user = User::factory()->create([
        'role' => $role,
        'username' => $role.'-report-'.$suffix,
    ]);

    return [$user, $user->createToken('report-test')->plainTextToken];
}

function seedDocumentedFinanceExample(User $owner): void
{
    $supplier = Supplier::query()->create(['name' => 'Supplier Laporan']);
    Purchase::query()->create([
        'supplier_id' => $supplier->id,
        'purchased_at' => '2026-10-22',
        'gross_weight_kg' => '100.000',
        'price_per_kg' => 17000,
        'total_cost' => 1700000,
        'status' => 'finalized',
        'cost_per_clean_kg' => 21250,
        'clean_weight_kg' => '80.000',
        'finalized_at' => '2026-10-22 01:00:00',
        'created_by' => $owner->id,
    ]);

    $product = Product::query()->create([
        'name' => 'Merah A',
        'price_retail' => 27500,
        'price_3kg' => 26000,
        'price_5kg' => 24000,
    ]);

    $completed = Transaction::query()->create([
        'invoice_no' => 'POS-261022-0001',
        'client_uuid' => '11111111-1111-4111-8111-111111111111',
        'cashier_id' => $owner->id,
        'customer_category' => 'ecer',
        'subtotal' => 1500000,
        'discount' => 0,
        'total' => 1500000,
        'amount_paid' => 1500000,
        'payment_method' => 'cash',
        'status' => 'completed',
        'occurred_at' => '2026-10-22 03:00:00',
    ]);
    TransactionItem::query()->create([
        'transaction_id' => $completed->id,
        'product_id' => $product->id,
        'product_name' => $product->name,
        'weight_kg' => '60.000',
        'price_tier' => 'retail',
        'price_per_kg' => 25000,
        'line_total' => 1500000,
        'cogs' => 1275000,
    ]);

    Transaction::query()->create([
        'invoice_no' => 'POS-261022-0002',
        'client_uuid' => '22222222-2222-4222-8222-222222222222',
        'cashier_id' => $owner->id,
        'customer_category' => 'ecer',
        'subtotal' => 10000,
        'discount' => 0,
        'total' => 10000,
        'amount_paid' => 10000,
        'payment_method' => 'cash',
        'status' => 'void',
        'occurred_at' => '2026-10-22 04:00:00',
        'void_reason' => 'Tes laporan',
        'voided_by' => $owner->id,
        'voided_at' => '2026-10-22 04:30:00',
    ]);

    StockCount::query()->create([
        'product_id' => $product->id,
        'counted_weight_kg' => '18.500',
        'system_weight_kg' => '20.000',
        'diff_kg' => '-1.500',
        'unit_cost_avg' => 21250,
        'loss_value' => 31875,
        'counted_at' => '2026-10-22 05:00:00',
        'counted_by' => $owner->id,
    ]);

    Expense::query()->create([
        'name' => 'Operasional',
        'category' => 'lainnya',
        'amount' => 50000,
        'spent_at' => '2026-10-22',
        'created_by' => $owner->id,
    ]);
}

it('calculates the documented monthly finance report example', function () {
    [$owner, $token] = reportUser('owner', 'monthly');
    seedDocumentedFinanceExample($owner);

    $this->withToken($token)
        ->getJson('/api/v1/reports/finance?period=monthly&month=2026-10')
        ->assertOk()
        ->assertJsonPath('data.period', 'monthly')
        ->assertJsonPath('data.month', '2026-10')
        ->assertJsonPath('data.net_sales', 1500000)
        ->assertJsonPath('data.cogs', 1275000)
        ->assertJsonPath('data.display_shrink_value', 31875)
        ->assertJsonPath('data.operating_expenses', 50000)
        ->assertJsonPath('data.net_profit', 143125)
        ->assertJsonPath('data.purchases_total', 1700000)
        ->assertJsonPath('data.cash_flow', -250000)
        ->assertJsonPath('data.cleaning_shrink_kg', '20.000')
        ->assertJsonPath('data.shortfall_count', 0)
        ->assertJsonPath('data.void_count', 1);
});

it('calculates the same finance rules for a daily period and rejects cashier access', function () {
    [$owner, $ownerToken] = reportUser('owner', 'daily');
    seedDocumentedFinanceExample($owner);

    $this->withToken($ownerToken)
        ->getJson('/api/v1/reports/finance?period=daily&date=2026-10-22')
        ->assertOk()
        ->assertJsonPath('data.period', 'daily')
        ->assertJsonPath('data.date', '2026-10-22')
        ->assertJsonPath('data.net_profit', 143125);

    [, $cashierToken] = reportUser('kasir', 'denied');
    $this->app['auth']->forgetGuards();

    $this->withToken($cashierToken)
        ->getJson('/api/v1/reports/finance?period=daily&date=2026-10-22')
        ->assertForbidden();
});

it('validates finance report period parameters', function () {
    [, $token] = reportUser('owner', 'validation');

    $this->withToken($token)
        ->getJson('/api/v1/reports/finance?period=weekly')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['period']);
});

it('H-42 assigns daily sales to the correct WIB business date', function () {
    [$owner, $token] = reportUser('owner', 'h42-day-boundary');
    foreach ([
        ['POS-H42-0530', '2026-10-25 22:30:00', 30000],
        ['POS-H42-2359', '2026-10-26 16:59:00', 40000],
        ['POS-H42-NEXT', '2026-10-26 17:00:00', 90000],
    ] as [$invoice, $occurredAt, $total]) {
        Transaction::query()->create([
            'invoice_no' => $invoice,
            'client_uuid' => fake()->uuid(),
            'cashier_id' => $owner->id,
            'customer_category' => 'ecer',
            'subtotal' => $total,
            'discount' => 0,
            'total' => $total,
            'amount_paid' => $total,
            'payment_method' => 'cash',
            'status' => 'completed',
            'occurred_at' => $occurredAt,
        ]);
    }

    $this->withToken($token)->getJson('/api/v1/reports/finance?period=daily&date=2026-10-26')
        ->assertOk()->assertJsonPath('data.net_sales', 70000);
    $this->withToken($token)->getJson('/api/v1/reports/finance?period=daily&date=2026-10-27')
        ->assertOk()->assertJsonPath('data.net_sales', 90000);
});

it('H-43 assigns transactions at the October-November WIB boundary to the correct month', function () {
    [$owner, $token] = reportUser('owner', 'h43-month-boundary');
    foreach ([
        ['POS-H43-OCT', '2026-10-31 16:59:00', 100000],
        ['POS-H43-NOV', '2026-10-31 17:00:00', 200000],
    ] as [$invoice, $occurredAt, $total]) {
        Transaction::query()->create([
            'invoice_no' => $invoice,
            'client_uuid' => fake()->uuid(),
            'cashier_id' => $owner->id,
            'customer_category' => 'ecer',
            'subtotal' => $total,
            'discount' => 0,
            'total' => $total,
            'amount_paid' => $total,
            'payment_method' => 'cash',
            'status' => 'completed',
            'occurred_at' => $occurredAt,
        ]);
    }

    $this->withToken($token)->getJson('/api/v1/reports/finance?period=monthly&month=2026-10')
        ->assertOk()->assertJsonPath('data.net_sales', 100000);
    $this->withToken($token)->getJson('/api/v1/reports/finance?period=monthly&month=2026-11')
        ->assertOk()->assertJsonPath('data.net_sales', 200000);
});

it('H-44 prevents date extraction filters on UTC timestamp columns in application code', function () {
    $filesystem = new Filesystem();
    $violations = [];
    $patterns = [
        '/whereDate\s*\(\s*[\'\"](?:[a-z_]+\.)?(?:occurred_at|counted_at)[\'\"]/i',
        '/\bDATE\s*\(\s*(?:[a-z_]+\.)?(?:occurred_at|counted_at)\s*\)/i',
        '/\bDATE\s*\(\s*DATE_(?:ADD|SUB)\s*\([^)]*(?:occurred_at|counted_at)/i',
        '/\bDATE\s*\(\s*DATETIME\s*\([^)]*(?:occurred_at|counted_at)/i',
    ];

    foreach ($filesystem->allFiles(app_path()) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $contents = $filesystem->get($file->getPathname());
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $contents) === 1) {
                if ($file->getRelativePathname() !== 'Support/BusinessDay.php'
                    || !str_contains($contents, 'public static function localDateExpression')) {
                    $violations[] = $file->getRelativePathname();
                }
            }
        }
    }

    expect($violations)->toBeEmpty();
});
