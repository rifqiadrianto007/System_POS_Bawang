<?php

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function quoteToken(string $role = 'kasir'): string
{
    $user = User::factory()->create([
        'role' => $role,
        'username' => $role.'-quote',
    ]);

    return $user->createToken('quote-test')->plainTextToken;
}

function quoteProduct(string $name, int $retail = 27500, int $threeKg = 26000, int $fiveKg = 24000): Product
{
    return Product::query()->create([
        'name' => $name,
        'price_retail' => $retail,
        'price_3kg' => $threeKg,
        'price_5kg' => $fiveKg,
    ]);
}

it('returns repeated products as separately priced item lines', function () {
    $red = quoteProduct('Merah A');
    $white = quoteProduct('Putih A', 28000, 26000, 24000);

    $response = $this->withToken(quoteToken())->postJson('/api/v1/transactions/quote', [
        'customer_category' => 'ecer',
        'items' => [
            ['product_id' => $red->id, 'weight_kg' => '1.000'],
            ['product_id' => $red->id, 'weight_kg' => '0.500'],
            ['product_id' => $white->id, 'weight_kg' => '4.000'],
        ],
    ]);

    $response->assertOk()
        ->assertJsonPath('data.items.0.product_id', $red->id)
        ->assertJsonPath('data.items.0.product_name', 'Merah A')
        ->assertJsonPath('data.items.0.weight_kg', '1.000')
        ->assertJsonPath('data.items.0.price_tier', 'retail')
        ->assertJsonPath('data.items.0.price_per_kg', 27500)
        ->assertJsonPath('data.items.0.line_total', 27500)
        ->assertJsonPath('data.items.1.product_id', $red->id)
        ->assertJsonPath('data.items.1.weight_kg', '0.500')
        ->assertJsonPath('data.items.1.line_total', 14000)
        ->assertJsonPath('data.items.2.product_id', $white->id)
        ->assertJsonPath('data.items.2.weight_kg', '4.000')
        ->assertJsonPath('data.items.2.price_tier', 'retail')
        ->assertJsonPath('data.items.2.line_total', 112000)
        ->assertJsonPath('data.subtotal', 153500)
        ->assertJsonPath('data.discount', 0)
        ->assertJsonPath('data.total', 153500);
});

it('rounds retail item lines below one kilogram up to five hundred rupiah', function () {
    $a = quoteProduct('Merah Pembulatan 5800', 23200);
    $b = quoteProduct('Merah Pembulatan 6250', 25000);
    $c = quoteProduct('Merah Pembulatan 5000', 25000);
    $d = quoteProduct('Merah Pembulatan 1 kg', 23200);
    $response = $this->withToken(quoteToken())->postJson('/api/v1/transactions/quote', [
        'customer_category' => 'ecer',
        'items' => [
            ['product_id' => $a->id, 'weight_kg' => '0.250'],
            ['product_id' => $b->id, 'weight_kg' => '0.250'],
            ['product_id' => $c->id, 'weight_kg' => '0.200'],
            ['product_id' => $d->id, 'weight_kg' => '1.000'],
        ],
    ])->assertOk();

    expect(array_column($response->json('data.items'), 'line_total'))->toBe([6000, 6500, 5000, 23200]);
});

it('supports the documented final total discount', function () {
    $product = quoteProduct('Merah A');

    $this->withToken(quoteToken('owner'))
        ->postJson('/api/v1/transactions/quote', [
            'customer_category' => 'ecer',
            'items' => [
                ['product_id' => $product->id, 'weight_kg' => '2.727'],
            ],
            'final_total' => 70000,
        ])
        ->assertOk()
        ->assertJsonPath('data.subtotal', 75000)
        ->assertJsonPath('data.discount', 5000)
        ->assertJsonPath('data.total', 70000);
});

it('rejects an invalid final total with the standard validation shape', function (int $finalTotal) {
    $product = quoteProduct('Merah A');

    $this->withToken(quoteToken())
        ->postJson('/api/v1/transactions/quote', [
            'customer_category' => 'ecer',
            'items' => [
                ['product_id' => $product->id, 'weight_kg' => '2.727'],
            ],
            'final_total' => $finalTotal,
        ])
        ->assertUnprocessable()
        ->assertJsonStructure(['message']);
})->with([75001, -1]);

it('validates the quote request and requires authentication', function () {
    $this->postJson('/api/v1/transactions/quote', [
        'customer_category' => 'invalid',
        'items' => [],
    ])->assertUnauthorized();

    $this->withToken(quoteToken())
        ->postJson('/api/v1/transactions/quote', [
            'customer_category' => 'ecer',
            'items' => [],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['items']);
});
