<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $accounts = [
            ['username' => 'owner', 'name' => 'Owner POS Bawang', 'email' => 'owner@posbawang.test', 'role' => 'owner', 'password' => env('SEED_OWNER_PASSWORD')],
            ['username' => 'kasir', 'name' => 'Kasir', 'email' => 'kasir@posbawang.test', 'role' => 'kasir', 'password' => env('SEED_KASIR_PASSWORD')]
        ];

        $missingPasswords = array_filter($accounts, static fn (array $account): bool => ! is_string($account['password']) || trim($account['password']) === '');
        if (app()->environment('production') && $missingPasswords !== []) {
            throw new RuntimeException('Seeder produksi memerlukan SEED_OWNER_PASSWORD dan SEED_KASIR_PASSWORD.');
        }

        foreach ($accounts as $account) {
            $password = is_string($account['password']) && trim($account['password']) !== ''
                ? $account['password']
                : 'password'; // Fallback for local development only.

            User::query()->updateOrCreate(
                ['username' => $account['username']],
                [
                    'name' => $account['name'],
                    'email' => $account['email'],
                    'password' => $password,
                    'role' => $account['role'],
                    'is_active' => true,
                ]
            );
        }
    }
}
