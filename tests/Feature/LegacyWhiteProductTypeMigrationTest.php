<?php

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('repairs legacy white products that were saved as merah', function () {
    $white = Product::query()->create([
        'name' => 'Putih Standar B', 'jenis' => 'merah', 'price_retail' => 27000, 'price_5kg' => 23000,
    ]);
    $whitePrefixed = Product::query()->create([
        'name' => 'Bawang Putih Super A', 'jenis' => 'merah', 'price_retail' => 27000, 'price_5kg' => 23000,
    ]);
    $red = Product::query()->create([
        'name' => 'Merah Standar B', 'jenis' => 'merah', 'price_retail' => 27000, 'price_5kg' => 23000,
    ]);

    $migration = require database_path('migrations/2026_11_11_130000_repair_legacy_white_product_types.php');
    $migration->up();

    expect($white->refresh()->jenis)->toBe('putih')
        ->and($whitePrefixed->refresh()->jenis)->toBe('putih')
        ->and($red->refresh()->jenis)->toBe('merah');
});
