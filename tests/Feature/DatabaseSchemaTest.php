<?php

use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class)->in(__FILE__);

it('creates every table required by the data model', function () {
    $tables = [
        'users',
        'products',
        'product_price_histories',
        'suppliers',
        'purchases',
        'stock_lots',
        'stock_movements',
        'stock_counts',
        'transactions',
        'transaction_items',
        'sale_allocations',
        'expenses',
        'audit_logs',
    ];

    foreach ($tables as $table) {
        expect(Schema::hasTable($table))->toBeTrue();
    }
});

it('stores the business fields and relationships from the data model', function () {
    $owner = User::factory()->create([
        'role' => 'owner',
        'username' => 'owner',
    ]);
    $product = Product::query()->create([
        'name' => 'Merah A',
        'price_retail' => 27500,
        'price_3kg' => 26000,
        'price_5kg' => 24000,
    ]);
    $supplier = Supplier::query()->create(['name' => 'Supplier A']);
    $purchase = $supplier->purchases()->create([
        'purchased_at' => '2026-10-19',
        'gross_weight_kg' => '100.000',
        'price_per_kg' => 17000,
        'total_cost' => 1700000,
        'created_by' => $owner->id,
    ]);
    $lot = $purchase->stockLots()->create([
        'product_id' => $product->id,
        'clean_weight_kg' => '40.000',
        'cleaned_at' => '2026-10-19',
    ]);
    $transaction = $owner->cashierTransactions()->create([
        'invoice_no' => 'POS-261019-0001',
        'client_uuid' => '2f9c6a52-5f55-4f4e-9d4a-0c5f9d1a7b11',
        'customer_category' => 'ecer',
        'subtotal' => 6900,
        'total' => 6900,
        'payment_method' => 'cash',
        'occurred_at' => now(),
    ]);

    expect($owner->fresh()->cashierTransactions)->toHaveCount(1)
        ->and($supplier->fresh()->purchases)->toHaveCount(1)
        ->and($purchase->fresh()->stockLots)->toHaveCount(1)
        ->and($lot->fresh()->product->is($product))->toBeTrue()
        ->and($transaction->fresh()->cashier->is($owner))->toBeTrue()
        ->and($product->fresh()->stockLots->first()->is($lot))->toBeTrue();
});
