<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
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

        Supplier::query()->create([
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
        $products = [$redA, $redB, $redC, $whiteA];

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

            $transaction = $transactionService->create([
                'client_uuid' => sprintf('a0000000-0000-4000-8000-%012d', $number),
                'customer_category' => $category,
                'customer_name' => $number % 2 === 0 ? 'Pelanggan Demo' : null,
                'items' => $items,
                'payment_method' => 'cash',
                'final_total' => $isDiscountedOverpayment ? 70000 : null,
                'amount_paid' => $amountPaid,
                'occurred_at' => Carbon::parse('2026-10-01 02:00:00', 'UTC')
                    ->addDays($number - 1)
                    ->setTime(2 + ($number % 12), 0)
                    ->toIso8601String(),
            ], $cashier->id);

            if ($number === 20) {
                $transactionService->void($transaction, $owner->id, 'Contoh transaksi void demo');
            }
        }
    }
}
