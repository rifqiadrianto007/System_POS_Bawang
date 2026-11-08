<?php

use App\Models\Expense;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\StockCount;
use App\Models\Supplier;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

it('seeds exactly one owner and one cashier, and can be run repeatedly', function () {
    $this->seed();
    $this->seed();

    expect(User::query()->count())->toBe(2)
        ->and(User::query()->where('role', 'owner')->count())->toBe(1)
        ->and(User::query()->where('role', 'kasir')->count())->toBe(1)
        ->and(User::query()->pluck('username')->all())->toEqualCanonicalizing(['owner', 'kasir'])
        ->and(Product::query()->count())->toBe(0)
        ->and(Supplier::query()->count())->toBe(0)
        ->and(Purchase::query()->count())->toBe(0)
        ->and(Transaction::query()->count())->toBe(0)
        ->and(Expense::query()->count())->toBe(0)
        ->and(StockCount::query()->count())->toBe(0);
});

it('uses the configured environment passwords for both accounts', function () {
    $passwords = [
        'SEED_OWNER_PASSWORD' => 'owner-seed-password',
        'SEED_KASIR_PASSWORD' => 'kasir-seed-password',
    ];
    $previous = [];
    foreach ($passwords as $key => $value) {
        $previous[$key] = getenv($key);
        putenv($key.'='.$value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    try {
        $this->seed();

        foreach ([
            'owner' => 'owner-seed-password',
            'kasir' => 'kasir-seed-password'
        ] as $username => $password) {
            expect(Hash::check($password, User::query()->where('username', $username)->firstOrFail()->password))->toBeTrue();
        }
    } finally {
        foreach ($previous as $key => $value) {
            if ($value === false) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);
            } else {
                putenv($key.'='.$value);
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
            }
        }
    }
});

it('refuses production seeding when any account password is missing', function () {
    $keys = ['SEED_OWNER_PASSWORD', 'SEED_KASIR_PASSWORD'];
    $previous = [];
    foreach ($keys as $key) {
        $previous[$key] = getenv($key);
        putenv($key);
        unset($_ENV[$key], $_SERVER[$key]);
    }

    config(['app.env' => 'production']);

    try {
        app()->detectEnvironment(static fn (): string => 'production');
        expect(fn () => app(\Database\Seeders\DatabaseSeeder::class)->run())
            ->toThrow(\RuntimeException::class, 'Seeder produksi memerlukan');
    } finally {
        app()->detectEnvironment(static fn (): string => 'testing');
        foreach ($previous as $key => $value) {
            if ($value !== false) {
                putenv($key.'='.$value);
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
            }
        }
    }
});
