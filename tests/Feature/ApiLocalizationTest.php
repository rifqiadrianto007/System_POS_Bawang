<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

function localizationEnglishErrors(array $errors): array
{
    $english = '/\b(the|field|required|must|be|is|invalid|selected|greater|than|least|may)\b/i';
    $snakeCase = '/\b[a-z][a-z0-9]*_[a-z0-9_]+\b/';
    $matches = [];

    foreach ($errors as $field => $messages) {
        foreach ($messages as $message) {
            if (preg_match($english, $message) || preg_match($snakeCase, $message)) {
                $matches[] = [$field, $message];
            }
        }
    }

    return $matches;
}

it('LC-01 localizes validation errors from every write route', function () {
    $owner = User::factory()->create(['role' => 'owner', 'username' => 'locale-owner']);
    $this->actingAs($owner, 'sanctum');

    foreach (Route::getRoutes() as $route) {
        if (! str_starts_with($route->uri(), 'api/v1/')) {
            continue;
        }

        foreach ($route->methods() as $method) {
            if (! in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
                continue;
            }

            $path = preg_replace('/\{[^}]+\}/', '1', '/'.$route->uri());
            $response = $this->json($method, $path, [], ['Accept' => 'application/json']);
            $errors = $response->json('errors', []) ?? [];
            expect(localizationEnglishErrors($errors))->toBe([], $method.' '.$path.' returned untranslated errors');
        }
    }
});

it('LC-02 returns Indonesian messages for login failure, authentication, authorization, and missing routes', function () {
    $this->postJson('/api/v1/auth/login', ['username' => 'unknown-user', 'password' => 'salah'])
        ->assertUnauthorized()->assertJsonPath('message', 'Username atau password salah.');

    $this->getJson('/api/v1/auth/me')->assertUnauthorized()
        ->assertJsonPath('message', 'Tidak terautentikasi.');

    $cashier = User::factory()->create(['role' => 'kasir', 'username' => 'locale-cashier']);
    $this->actingAs($cashier, 'sanctum')->getJson('/api/v1/owner/ping')->assertForbidden()
        ->assertJsonPath('message', 'Akses hanya untuk owner.');

    $this->getJson('/api/v1/route-yang-tidak-ada')->assertNotFound()
        ->assertJsonPath('message', 'Data tidak ditemukan.');
});

it('LC-03 preserves the custom Indonesian expense validation messages', function () {
    $owner = User::factory()->create(['role' => 'owner', 'username' => 'locale-expense-owner']);

    $this->actingAs($owner, 'sanctum')->postJson('/api/v1/expenses', [
        'name' => '', 'amount' => 0, 'spent_at' => 'not-a-date',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['name', 'category', 'amount', 'spent_at'])
        ->assertJsonPath('errors.name.0', 'Nama pengeluaran wajib diisi.')
        ->assertJsonPath('errors.category.0', 'Kategori pengeluaran wajib dipilih.')
        ->assertJsonPath('errors.amount.0', 'Jumlah pengeluaran minimal Rp1.')
        ->assertJsonPath('errors.spent_at.0', 'Tanggal pengeluaran harus berformat YYYY-MM-DD.');
});

it('LC-04 uses Indonesian as the application locale and English as fallback', function () {
    expect(config('app.locale'))->toBe('id')
        ->and(config('app.fallback_locale'))->toBe('en');
});
