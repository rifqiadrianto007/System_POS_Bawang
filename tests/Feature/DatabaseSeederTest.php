<?php

use App\Models\Product;
use App\Models\Supplier;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('seeds one owner, two cashiers, four products, and two suppliers', function () {
    $this->seed();

    expect(User::query()->where('role', 'owner')->count())->toBe(1)
        ->and(User::query()->where('role', 'kasir')->count())->toBe(2)
        ->and(Product::query()->count())->toBe(4)
        ->and(Supplier::query()->count())->toBe(2)
        ->and(Supplier::query()->pluck('name')->all())
        ->toEqualCanonicalizing(['Supplier Bawang Merah', 'Supplier Bawang Putih']);
});

it('seeds the documented Merah A prices', function () {
    $this->seed();

    $product = Product::query()->where('name', 'Merah A')->firstOrFail();

    expect($product->price_retail)->toBe(27500)
        ->and($product->price_3kg)->toBe(26000)
        ->and($product->price_5kg)->toBe(24000)
        ->and($product->is_active)->toBeTrue();
});

it('seeds twenty transactions with overpayments, one discounted overpayment, and calculable net sales', function () {
    $this->seed();

    $transactions = Transaction::query()->orderBy('id')->get();
    $discounted = $transactions->firstWhere('discount', '>', 0);

    expect($transactions)->toHaveCount(20)
        ->and($transactions->filter(fn (Transaction $transaction): bool => (int) $transaction->amount_paid > (int) $transaction->total))->toHaveCount(7)
        ->and($discounted->subtotal)->toBe(75000)
        ->and($discounted->total)->toBe(70000)
        ->and($discounted->amount_paid)->toBe(100000)
        ->and((int) $transactions->where('status', 'completed')->sum('total'))->toBe(182600)
        ->and($transactions->where('status', 'void'))->toHaveCount(1);
});
