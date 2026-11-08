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
        $attributes = ['supplier_id' => $supplier->id, 'purchased_at' => '2026-10-27', 'jenis' => 'merah', 'gross_weight_kg' => '100.000', 'price_per_kg' => 20000, 'notes' => 'Kulak fixture'];
        $closed = $service->create($attributes, $owner->id);
        $service->addLot($closed, ['product_id' => $redA->id, 'weight_kg' => '40.000', 'sorted_at' => '2026-10-27', 'notes' => 'Grade A'], $owner->id);
        $service->addLot($closed, ['product_id' => $redB->id, 'weight_kg' => '30.000', 'sorted_at' => '2026-10-27', 'notes' => 'Grade B'], $owner->id);
        $service->addLot($closed, ['product_id' => $redC->id, 'weight_kg' => '28.000', 'sorted_at' => '2026-10-27', 'notes' => 'Grade C'], $owner->id);
        $closed = $service->close($closed, $owner->id);

        $sorting = $service->create([...$attributes, 'gross_weight_kg' => '20.000', 'notes' => 'Kulak sorting'], $owner->id);
        $service->addLot($sorting, ['product_id' => $redA->id, 'weight_kg' => '5.000', 'sorted_at' => '2026-10-27'], $owner->id);
        $unsorted = $service->create([...$attributes, 'gross_weight_kg' => '10.000', 'notes' => 'Kulak belum disortir'], $owner->id);

        $transaction = app(TransactionService::class)->create([
            'client_uuid' => 'd1000000-0000-4000-8000-000000000001', 'customer_category' => 'ecer', 'customer_name' => 'Pelanggan Contoh',
            'items' => [['product_id' => $redA->id, 'weight_kg' => '50.000']], 'payment_method' => 'cash', 'final_total' => 1200000,
            'amount_paid' => 1400000, 'occurred_at' => '2026-10-27 10:00:00',
        ], $cashier->id);

        $countService = app(StockCountService::class);
        $countOld = $countService->create(['product_id' => $redA->id, 'counted_weight_kg' => '3.000', 'counted_at' => '2026-10-27 10:10:00', 'notes' => 'Contoh lama'], $owner->id);
        $countLatest = $countService->create(['product_id' => $redA->id, 'counted_weight_kg' => '2.000', 'counted_at' => '2026-10-27 10:20:00', 'notes' => 'Contoh terbaru'], $owner->id);
        $countCancelled = $countService->create(['product_id' => $white->id, 'counted_weight_kg' => '0.000', 'counted_at' => '2026-10-27 10:25:00', 'notes' => 'Contoh batal'], $owner->id);
        $countCancelled = $countService->cancel($countCancelled, 'Pembatalan fixture terbaru', $owner->id);

        Expense::query()->create(['name' => 'Plastik kemasan', 'category' => 'lainnya', 'amount' => 50000, 'spent_at' => '2026-10-27', 'notes' => 'Contoh fixture', 'created_by' => $owner->id]);

        return ['owner' => $owner, 'cashier' => $cashier, 'product' => $redA,
            'products' => [$redA, $redB, $redC, $white], 'supplier' => $supplier, 'closed_purchase' => $closed,
            'sorting_purchase' => $sorting->fresh(), 'unsorted_purchase' => $unsorted, 'closed_lot' => $closed->stockLots()->first(),
            'sorting_lot' => $sorting->stockLots()->first(), 'expense' => Expense::query()->first(), 'transaction' => $transaction,
            'count_old' => $countOld, 'count_latest' => $countLatest, 'count_cancelled' => $countCancelled];
    }
}
