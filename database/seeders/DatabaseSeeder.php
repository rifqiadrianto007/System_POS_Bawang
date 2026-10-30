<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use App\Services\ExpenseService;
use App\Services\PurchaseService;
use App\Services\TransactionService;
use App\Services\PricingService;
use Carbon\Carbon;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $owner = User::factory()->create([
            'name' => 'Owner POS Bawang',
            'username' => 'owner',
            'email' => 'owner@posbawang.test',
            'password' => 'password',
            'role' => 'owner',
            'is_active' => true,
        ]);

        $cashierMorning = User::factory()->create([
            'name' => 'Kasir Pagi',
            'username' => 'kasir.pagi',
            'email' => 'kasir.pagi@posbawang.test',
            'password' => 'password',
            'role' => 'kasir',
            'is_active' => true,
        ]);

        $cashierEvening = User::factory()->create([
            'name' => 'Kasir Sore',
            'username' => 'kasir.sore',
            'email' => 'kasir.sore@posbawang.test',
            'password' => 'password',
            'role' => 'kasir',
            'is_active' => true,
        ]);

        $redA = Product::query()->create([
            'name' => 'Merah A',
            'price_retail' => 27500,
            'price_3kg' => 26000,
            'price_5kg' => 24000,
            'is_active' => true,
        ]);

        $redB = Product::query()->create([
            'name' => 'Merah B',
            'price_retail' => 25000,
            'price_3kg' => 24000,
            'price_5kg' => 22000,
            'is_active' => true,
        ]);

        $redC = Product::query()->create([
            'name' => 'Merah C',
            'price_retail' => 22000,
            'price_3kg' => 21000,
            'price_5kg' => 19000,
            'is_active' => true,
        ]);

        $whiteA = Product::query()->create([
            'name' => 'Putih A',
            'price_retail' => 30000,
            'price_3kg' => 28500,
            'price_5kg' => 26000,
            'is_active' => true,
        ]);

        $redSupplier = Supplier::query()->create([
            'name' => 'Supplier Bawang Merah',
            'phone' => '081234567801',
            'note' => 'Pemasok bawang merah',
        ]);

        Supplier::query()->create([
            'name' => 'Supplier Bawang Putih',
            'phone' => '081234567802',
            'note' => 'Pemasok bawang putih',
        ]);

        $transactionService = app(TransactionService::class);
        $pricingService = app(PricingService::class);
        $purchaseService = app(PurchaseService::class);
        $expenseService = app(ExpenseService::class);
        $products = [$redA, $redB, $redC, $whiteA];

        $finalPurchase = $purchaseService->create([
            'supplier_id' => $redSupplier->id,
            'purchased_at' => '2026-10-01',
            'gross_weight_kg' => '100.000',
            'price_per_kg' => 17000,
            'notes' => 'Batch contoh laporan: 100 kg x Rp17.000.',
        ], $owner->id);
        foreach ([[$redA, '40.000'], [$redB, '25.000'], [$whiteA, '15.000']] as [$product, $weight]) {
            $purchaseService->addLot($finalPurchase, [
                'product_id' => $product->id,
                'clean_weight_kg' => $weight,
                'cleaned_at' => '2026-10-01',
            ], $owner->id);
        }
        $purchaseService->finalize($finalPurchase, $owner->id);

        $openPurchase = $purchaseService->create([
            'supplier_id' => $redSupplier->id,
            'purchased_at' => '2026-10-26',
            'gross_weight_kg' => '20.000',
            'price_per_kg' => 15000,
            'notes' => 'Kulak open untuk contoh perubahan.',
        ], $owner->id);
        $lotPurchase = $purchaseService->create([
            'supplier_id' => $redSupplier->id,
            'purchased_at' => '2026-10-26',
            'gross_weight_kg' => '10.000',
            'price_per_kg' => 14000,
            'notes' => 'Kulak open dengan lot untuk contoh finalisasi.',
        ], $owner->id);
        $purchaseService->addLot($lotPurchase, [
            'product_id' => $redC->id,
            'clean_weight_kg' => '5.000',
            'cleaned_at' => '2026-10-26',
        ], $owner->id);

        foreach ([
            ['name' => 'Plastik kemasan demo', 'category' => 'kemasan', 'amount' => 35000, 'spent_at' => '2026-10-01'],
            ['name' => 'Transport kulak demo', 'category' => 'transportasi', 'amount' => 50000, 'spent_at' => '2026-10-15'],
            ['name' => 'Listrik toko demo', 'category' => 'listrik_air', 'amount' => 60000, 'spent_at' => '2026-10-26'],
        ] as $expense) {
            $expenseService->create($expense, $owner->id);
        }

        for ($number = 1; $number <= 20; $number++) {
            $product = $products[($number - 1) % count($products)];
            $cashier = $number % 2 === 0 ? $cashierEvening : $cashierMorning;
            $isDiscountedOverpayment = $number === 1;
            $weight = $isDiscountedOverpayment ? '2.727' : '0.250';
            $category = $number % 4 === 0 ? 'grosir' : 'ecer';
            $items = [['product_id' => $product->id, 'weight_kg' => $weight]];
            $subtotal = $pricingService->quoteCart($category, $items)['subtotal'];
            $total = $isDiscountedOverpayment ? 70000 : $subtotal;
            $amountPaid = $isDiscountedOverpayment || $number % 3 === 0 ? $total + 30000 : $total;
            $occurredAt = match ($number) {
                1 => Carbon::parse('2026-10-25 22:30:00', 'UTC'), // 05:30 WIB
                2 => Carbon::parse('2026-10-26 16:59:00', 'UTC'), // 23:59 WIB
                3 => Carbon::parse('2026-10-26 17:00:00', 'UTC'), // 00:00 WIB on the next day
                default => Carbon::parse('2026-10-01 02:00:00', 'UTC')
                    ->addDays($number - 1)
                    ->setTime(2 + ($number % 12), 0),
            };

            $transaction = $transactionService->create([
                'client_uuid' => sprintf('a0000000-0000-4000-8000-%012d', $number),
                'customer_category' => $category,
                'customer_name' => $number % 2 === 0 ? 'Pelanggan Demo' : null,
                'items' => $items,
                'payment_method' => 'cash',
                'final_total' => $total,
                'amount_paid' => $amountPaid,
                'occurred_at' => $occurredAt->toIso8601String(),
            ], $cashier->id);

            if ($number === 20) {
                $transactionService->void($transaction, $owner->id, 'Contoh transaksi void demo');
            }
        }
    }
}
