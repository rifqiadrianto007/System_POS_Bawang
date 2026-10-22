<?php

use App\Models\AuditLog;
use App\Models\Expense;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function expenseUser(string $role, string $suffix): array
{
    $user = User::factory()->create([
        'role' => $role,
        'username' => $role.'-expense-'.$suffix,
    ]);

    return [$user, $user->createToken('expense-test')->plainTextToken];
}

function expensePayload(): array
{
    return [
        'name' => 'Listrik toko',
        'amount' => 50000,
        'spent_at' => '2026-10-22',
        'notes' => 'Tagihan bulan Oktober',
    ];
}

it('allows an owner to create and list operational expenses', function () {
    [$owner, $token] = expenseUser('owner', 'create');

    $created = $this->withToken($token)
        ->postJson('/api/v1/expenses', expensePayload())
        ->assertCreated()
        ->assertJsonPath('data.name', 'Listrik toko')
        ->assertJsonPath('data.amount', 50000)
        ->assertJsonPath('data.spent_at', '2026-10-22')
        ->assertJsonPath('data.notes', 'Tagihan bulan Oktober');

    expect(Expense::query()->whereKey($created->json('data.id'))->value('created_by'))
        ->toBe($owner->id);

    $this->withToken($token)
        ->getJson('/api/v1/expenses')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.amount', 50000);

    expect(AuditLog::query()->where('user_id', $owner->id)->pluck('action')->all())
        ->toContain('expense.created');
});

it('allows an owner to update an expense and records the audit', function () {
    [$owner, $token] = expenseUser('owner', 'update');
    $expense = Expense::query()->create([
        ...expensePayload(),
        'created_by' => $owner->id,
    ]);

    $this->withToken($token)
        ->putJson("/api/v1/expenses/{$expense->id}", [
            'name' => 'Sewa toko',
            'amount' => 75000,
            'spent_at' => '2026-10-23',
            'notes' => null,
        ])
        ->assertOk()
        ->assertJsonPath('data.name', 'Sewa toko')
        ->assertJsonPath('data.amount', 75000)
        ->assertJsonPath('data.spent_at', '2026-10-23')
        ->assertJsonPath('data.notes', null);

    expect(AuditLog::query()->where('user_id', $owner->id)->pluck('action')->all())
        ->toContain('expense.updated');
});

it('rejects cashier access and invalid expense values', function () {
    [, $cashierToken] = expenseUser('kasir', 'denied');

    $this->withToken($cashierToken)
        ->getJson('/api/v1/expenses')
        ->assertForbidden();

    $this->withToken($cashierToken)
        ->postJson('/api/v1/expenses', expensePayload())
        ->assertForbidden();

    [, $ownerToken] = expenseUser('owner', 'invalid');
    $this->app['auth']->forgetGuards();

    $this->withToken($ownerToken)
        ->postJson('/api/v1/expenses', [
            'name' => '',
            'amount' => -1,
            'spent_at' => 'not-a-date',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name', 'amount', 'spent_at']);
});
