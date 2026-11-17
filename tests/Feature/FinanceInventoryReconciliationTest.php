<?php

use App\Models\Expense;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\StockLot;
use App\Models\User;
use App\Services\StockAdditionService;
use App\Services\StockCountService;
use App\Services\TransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('reconciles the 100 kg finance fixture and reports event-based shrink cards', function () {
    Carbon\Carbon::setTestNow(Carbon\Carbon::parse('2026-11-16 10:00:00', 'Asia/Jakarta'));
    try {
        $owner = User::factory()->create(['role' => 'owner']);
        $ownerToken = $owner->createToken('finance-fixture-owner')->plainTextToken;
        $cashier = User::factory()->create(['role' => 'kasir']);
        $supplier = Supplier::query()->create(['name' => 'Pemasok Fixture Finance']);
        $product = Product::query()->create([
            'name' => 'Merah Fixture Finance', 'jenis' => 'merah', 'price_retail' => 25000,
            'price_5kg' => 25000, 'is_active' => true,
        ]);
        $purchase = Purchase::query()->create([
            'code' => 'KLK-261116-901', 'supplier_id' => $supplier->id, 'purchased_at' => '2026-11-16',
            'jenis' => 'merah', 'gross_weight_kg' => '100.000', 'price_per_kg' => 20000,
            'total_cost' => 2000000, 'created_by' => $owner->id,
        ]);
        app(StockAdditionService::class)->create([
            'batch_id' => $purchase->id, 'product_id' => $product->id, 'weight_kg' => '98.000',
        ], $owner->id);
        $this->withToken($ownerToken)->postJson('/api/v1/purchases/'.$purchase->id.'/finish-sorting')
            ->assertOk()->assertJsonPath('data.cleaning_shrink_kg', '2.000')
            ->assertJsonPath('data.cleaning_loss_value', 40000);
        $sale = app(TransactionService::class)->create([
            'client_uuid' => '30000000-0000-4000-8000-000000000901', 'customer_category' => 'ecer',
            'items' => [['product_id' => $product->id, 'weight_kg' => '90.000']],
            'payment_method' => 'cash', 'final_total' => 2250000, 'amount_paid' => 2250000,
        ], $cashier->id);
        expect((int) $sale->items()->firstOrFail()->cogs)->toBe(1800000);
        app(StockCountService::class)->create([
            'product_id' => $product->id, 'counted_weight_kg' => '7.000',
            'counted_at' => '2026-11-16T10:01:00+07:00',
        ], $owner->id);
        Expense::query()->create([
            'name' => 'Operasional fixture', 'category' => 'lainnya', 'amount' => 20000,
            'spent_at' => '2026-11-16', 'created_by' => $owner->id,
        ]);

        $report = $this->withToken($ownerToken)->getJson('/api/v1/reports/finance?period=monthly&month=2026-11')
            ->assertOk()->json('data');
        expect($report['net_sales'])->toBe(2250000)
            ->and($report['cogs'])->toBe(1800000)
            ->and($report['display_shrink_value'])->toBe(20000)
            ->and($report['cleaning_loss_value'])->toBe(40000)
            ->and($report['shrink_value'])->toBe(60000)
            ->and($report['operating_expenses'])->toBe(20000)
            ->and($report['net_profit'])->toBe(370000)
            ->and($report['ending_inventory_value'])->toBe(140000)
            ->and($report['reconciliation_difference'])->toBe(0)
            ->and($report['reconciliation_mismatch'])->toBeFalse();

        $stock = $this->withToken($ownerToken)->getJson('/api/v1/stock?from=2026-11-16&to=2026-11-16')
            ->assertOk()->assertJsonMissingPath('totals.warehouse_kg')
            ->assertJsonPath('totals.display_kg', '7.000')->assertJsonPath('totals.unsorted_kg', '0.000')
            ->assertJsonPath('totals.sold_kg', '90.000')->assertJsonPath('totals.shrink_kg', '3.000')
            ->assertJsonPath('totals.shrink_value', 60000)->json('totals');
        expect($stock['inventory_value'])->toBe(140000)
            ->and(DB::table('monthly_inventory_snapshots')->where('month_start', '2026-11-01')->value('inventory_value'))->toBe(140000);
    } finally {
        Carbon\Carbon::setTestNow();
    }
});

it('does not treat legacy lots as additions when valuing unsorted inventory snapshots', function () {
    Carbon\Carbon::setTestNow(Carbon\Carbon::parse('2026-11-16 10:00:00', 'Asia/Jakarta'));
    try {
        $owner = User::factory()->create(['role' => 'owner']);
        $token = $owner->createToken('legacy-inventory-owner')->plainTextToken;
        $supplier = Supplier::query()->create(['name' => 'Pemasok Legacy Snapshot']);
        $product = Product::query()->create([
            'name' => 'Merah Legacy Snapshot', 'jenis' => 'merah', 'price_retail' => 25000,
            'price_5kg' => 25000, 'is_active' => true,
        ]);
        $purchase = Purchase::query()->create([
            'code' => 'KLK-261116-902', 'supplier_id' => $supplier->id, 'purchased_at' => '2026-11-16',
            'jenis' => 'merah', 'gross_weight_kg' => '10.000', 'price_per_kg' => 10000,
            'total_cost' => 100000, 'created_by' => $owner->id,
        ]);
        StockLot::query()->create([
            'purchase_id' => $purchase->id, 'product_id' => $product->id, 'weight_kg' => '4.000',
            'sorted_at' => '2026-11-16', 'unit_cost' => 10000, 'created_by' => $owner->id,
        ]);

        $report = $this->withToken($token)->getJson('/api/v1/reports/finance?period=monthly&month=2026-11')
            ->assertOk()->json('data');
        expect($report['ending_inventory_value'])->toBe(100000)
            ->and(DB::table('monthly_inventory_snapshots')->where('month_start', '2026-11-01')->value('inventory_value'))->toBe(100000);
    } finally {
        Carbon\Carbon::setTestNow();
    }
});
