<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

it('logs in a cashier with a token that does not expire', function () {
    $user = User::factory()->create([
        'username' => 'kasir',
        'password' => 'password',
        'role' => 'kasir',
    ]);

    $response = $this->postJson('/api/v1/auth/login', [
        'username' => 'kasir',
        'password' => 'password',
    ]);

    $response->assertCreated()
        ->assertJsonPath('user.username', $user->username)
        ->assertJsonPath('user.role', 'kasir')
        ->assertJsonMissingPath('user.password');

    expect($user->tokens()->first()->expires_at)->toBeNull();
});

it('logs in an owner with a token expiring in 45 minutes', function () {
    Carbon::setTestNow('2026-10-19 19:45:00');
    $user = User::factory()->create([
        'username' => 'owner',
        'password' => 'password',
        'role' => 'owner',
    ]);

    $response = $this->postJson('/api/v1/auth/login', [
        'username' => 'owner',
        'password' => 'password',
    ]);

    $response->assertCreated();

    expect($user->tokens()->first()->expires_at->equalTo(now()->addMinutes(45)))->toBeTrue();
    Carbon::setTestNow();
});

it('returns the authenticated user and logs out the current token', function () {
    $user = User::factory()->create(['username' => 'kasir']);
    $token = $user->createToken('test')->plainTextToken;

    $this->withToken($token)
        ->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('user.username', 'kasir');

    $this->withToken($token)
        ->postJson('/api/v1/auth/logout')
        ->assertOk()
        ->assertJsonPath('message', 'Berhasil logout.');

    $this->app['auth']->forgetGuards();
    $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
});

it('rejects a cashier from an owner route', function () {
    $user = User::factory()->create(['username' => 'kasir', 'role' => 'kasir']);
    $token = $user->createToken('test')->plainTextToken;

    $this->withToken($token)
        ->getJson('/api/v1/owner/ping')
        ->assertForbidden()
        ->assertJsonPath('message', 'Akses hanya untuk owner.');
});

it('allows an owner to access an owner route', function () {
    $user = User::factory()->create(['username' => 'owner', 'role' => 'owner']);
    $token = $user->createToken('test')->plainTextToken;

    $this->withToken($token)
        ->getJson('/api/v1/owner/ping')
        ->assertOk()
        ->assertJsonPath('message', 'Akses owner berhasil.');
});
