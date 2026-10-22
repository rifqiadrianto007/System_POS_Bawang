<?php

use App\Models\Product;
use App\Services\PricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function pricingProduct(string $name = 'Merah A'): Product
{
    return Product::query()->create([
        'name' => $name,
        'price_retail' => 27500,
        'price_3kg' => 26000,
        'price_5kg' => 24000,
    ]);
}

it('calculates the documented retail rounding examples', function (string $weight, int $lineTotal) {
    $product = pricingProduct();
    $quote = app(PricingService::class)->quoteCart('ecer', [
        ['product_id' => $product->id, 'weight_kg' => $weight],
    ]);

    expect($quote['items'])->toHaveCount(1)
        ->and($quote['items'][0]['price_tier'])->toBe('retail')
        ->and($quote['items'][0]['line_total'])->toBe($lineTotal);
})->with([
    ['0.250', 6900],
    ['0.350', 9600],
    ['1.500', 41300],
]);

it('selects the 3kg and 5kg tiers for ecer customers', function () {
    $product = pricingProduct();
    $service = app(PricingService::class);

    $threeKg = $service->quoteCart('ecer', [
        ['product_id' => $product->id, 'weight_kg' => '4.000'],
    ]);
    $fiveKg = $service->quoteCart('ecer', [
        ['product_id' => $product->id, 'weight_kg' => '5.000'],
    ]);

    expect($threeKg['items'][0]['price_tier'])->toBe('3kg')
        ->and($threeKg['items'][0]['line_total'])->toBe(104000)
        ->and($fiveKg['items'][0]['price_tier'])->toBe('5kg')
        ->and($fiveKg['items'][0]['line_total'])->toBe(120000);
});

it('forces the 5kg tier for grosir customers', function () {
    $product = pricingProduct();
    $quote = app(PricingService::class)->quoteCart('grosir', [
        ['product_id' => $product->id, 'weight_kg' => '4.000'],
    ]);

    expect($quote['items'][0]['price_tier'])->toBe('5kg')
        ->and($quote['items'][0]['price_per_kg'])->toBe(24000)
        ->and($quote['items'][0]['line_total'])->toBe(96000);
});

it('merges repeated products before selecting a tier', function () {
    $product = pricingProduct();
    $quote = app(PricingService::class)->quoteCart('ecer', [
        ['product_id' => $product->id, 'weight_kg' => '1.000'],
        ['product_id' => $product->id, 'weight_kg' => '0.500'],
    ]);

    expect($quote['items'])->toHaveCount(1)
        ->and($quote['items'][0]['weight_kg'])->toBe('1.500')
        ->and($quote['items'][0]['line_total'])->toBe(41300);
});

it('keeps different products in separate pricing lines', function () {
    $red = pricingProduct();
    $white = pricingProduct('Putih A');
    $quote = app(PricingService::class)->quoteCart('ecer', [
        ['product_id' => $red->id, 'weight_kg' => '2.000'],
        ['product_id' => $white->id, 'weight_kg' => '2.000'],
    ]);

    expect($quote['items'])->toHaveCount(2)
        ->and($quote['items'][0]['price_tier'])->toBe('retail')
        ->and($quote['items'][1]['price_tier'])->toBe('retail');
});

it('calculates the documented discount', function () {
    $product = pricingProduct();
    $quote = app(PricingService::class)->quoteCart('ecer', [
        ['product_id' => $product->id, 'weight_kg' => '2.727'],
    ], 70000);

    expect($quote['subtotal'])->toBe(75000)
        ->and($quote['discount'])->toBe(5000)
        ->and($quote['total'])->toBe(70000);
});

it('rejects a final total above subtotal or below zero', function (int $finalTotal) {
    $product = pricingProduct();

    expect(fn () => app(PricingService::class)->quoteCart('ecer', [
        ['product_id' => $product->id, 'weight_kg' => '2.727'],
    ], $finalTotal))->toThrow(InvalidArgumentException::class);
})->with([75001, -1]);

it('rejects weights outside the documented limits', function (string $weight) {
    $product = pricingProduct();

    expect(fn () => app(PricingService::class)->quoteCart('ecer', [
        ['product_id' => $product->id, 'weight_kg' => $weight],
    ]))->toThrow(InvalidArgumentException::class);
})->with(['0.049', '10000.000', '0.0500', 'abc']);
