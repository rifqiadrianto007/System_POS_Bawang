<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\User;
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
        User::factory()->create([
            'name' => 'Owner POS Bawang',
            'username' => 'owner',
            'email' => 'owner@posbawang.test',
            'password' => 'password',
            'role' => 'owner',
            'is_active' => true,
        ]);

        User::factory()->create([
            'name' => 'Kasir Pagi',
            'username' => 'kasir.pagi',
            'email' => 'kasir.pagi@posbawang.test',
            'password' => 'password',
            'role' => 'kasir',
            'is_active' => true,
        ]);

        User::factory()->create([
            'name' => 'Kasir Sore',
            'username' => 'kasir.sore',
            'email' => 'kasir.sore@posbawang.test',
            'password' => 'password',
            'role' => 'kasir',
            'is_active' => true,
        ]);

        Product::query()->create([
            'name' => 'Merah A',
            'price_retail' => 27500,
            'price_3kg' => 26000,
            'price_5kg' => 24000,
            'is_active' => true,
        ]);

        Product::query()->create([
            'name' => 'Merah B',
            'price_retail' => 25000,
            'price_3kg' => 24000,
            'price_5kg' => 22000,
            'is_active' => true,
        ]);

        Product::query()->create([
            'name' => 'Merah C',
            'price_retail' => 22000,
            'price_3kg' => 21000,
            'price_5kg' => 19000,
            'is_active' => true,
        ]);

        Product::query()->create([
            'name' => 'Putih A',
            'price_retail' => 30000,
            'price_3kg' => 28500,
            'price_5kg' => 26000,
            'is_active' => true,
        ]);
    }
}
