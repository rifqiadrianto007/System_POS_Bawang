<?php

namespace Tests\Support;

use App\Models\Expense;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PurchaseService;
use App\Services\StockCountService;
use App\Services\TransactionService;
use Illuminate\Support\Facades\Hash;

/** Deterministic API-example fixture. This class is test-only and is never called by db:seed. */
final class DemoData
{
    /** @return array<string, mixed> */
    public static function seed(): array
    {
        $owner = User::query()->create(['name' => 'Pemilik', 'username' => 'owner', 'email' => 'owner@example.test', 'password' => Hash::make('demo-owner-password'), 'role' => 'owner', 'is_active' => true]);
        $cashier = User::query()->create(['name' => 'Kasir', 'username' => 'kasir', 'email' => 'kasir@example.test', 'password' => Hash::make('demo-cashier-password'), 'role' => 'kasir', 'is_active' => true]);

        $redA = Product::query()->create(['name' => 'Merah A', 'jenis' => 'merah', 'price_retail' => 27500, 'price_5kg' => 24000, 'is_active' => true]);
        $redB = Product::query()->create(['name' => 'Merah B', 'jenis' => 'merah', 'price_retail' => 27000, 'price_5kg' => 23500, 'is_active' => true]);
        $redC = Product::query()->create(['name' => 'Merah C', 'jenis' => 'merah', 'price_retail' => 26000, 'price_5kg' => 23000, 'is_active' => true]);
        $white = Product::query()->create(['name' => 'Putih A', 'jenis' => 'putih', 'price_retail' => 29000, 'price_5kg' => 25000, 'is_active' => false]);
        $supplier = Supplier::query()->create(['name' => 'Pemasok Contoh', 'phone' => '081200000001', 'note' => 'Fixture API deterministik']);

        $service = app(PurchaseService::class);
        $countService = app(StockCountService::class);
        $attributes = ['supplier_id' => $supplier->id, 'purchased_at' => '2026-10-27', 'jenis' => 'merah', 'gross_weight_kg' => '100.000', 'price_per_kg' => 20000, 'notes' => 'Kulak fixture'];
        $closed = $service->create($attributes, $owner->id);
        $countOld = $countService->create(['purchase_id' => $closed->id, 'product_id' => $redA->id, 'counted_weight_kg' => '30.000', 'counted_at' => '2026-10-27 09:00:00', 'notes' => 'Grade A'], $owner->id);
        $countService->create(['purchase_id' => $closed->id, 'product_id' => $redB->id, 'counted_weight_kg' => '25.000', 'counted_at' => '2026-10-27 09:01:00', 'notes' => 'Grade B'], $owner->id);

        $transaction = app(TransactionService::class)->create([
            'client_uuid' => 'd1000000-0000-4000-8000-000000000001', 'customer_category' => 'ecer', 'customer_name' => 'Pelanggan Contoh',
            'items' => [['product_id' => $redC->id, 'weight_kg' => '1.000']], 'payment_method' => 'cash', 'final_total' => 26000,
            'amount_paid' => 26000, 'occurred_at' => '2026-10-27 10:00:00',
        ], $cashier->id);

        $countLatest = $countService->create(['purchase_id' => $closed->id, 'product_id' => $redA->id, 'counted_weight_kg' => '30.000', 'counted_at' => '2026-10-27 10:10:00', 'notes' => 'Contoh terbaru'], $owner->id);
        $countCancelled = $countService->create(['purchase_id' => $closed->id, 'product_id' => $redA->id, 'counted_weight_kg' => '30.000', 'counted_at' => '2026-10-27 10:15:00', 'notes' => 'Contoh hitung batal'], $owner->id);
        $countCancelled = $countService->cancel($countCancelled, 'Pembatalan fixture terbaru', $owner->id);

        Expense::query()->create(['name' => 'Plastik kemasan', 'category' => 'lainnya', 'amount' => 50000, 'spent_at' => '2026-10-27', 'notes' => 'Contoh fixture', 'created_by' => $owner->id]);

        return ['owner' => $owner, 'cashier' => $cashier, 'product' => $redA,
            'products' => [$redA, $redB, $redC, $white], 'supplier' => $supplier, 'primary_purchase' => $closed->fresh(),
            'primary_lot' => $closed->stockLots()->first(), 'expense' => Expense::query()->first(), 'transaction' => $transaction,
            'count_old' => $countOld, 'count_latest' => $countLatest, 'count_cancelled' => $countCancelled];
    }
}
