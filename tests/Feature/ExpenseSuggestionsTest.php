<?php

use App\Models\Expense;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function suggestionOwner(string $suffix = 'owner'): array
{
    $user = User::factory()->create([
        'role' => 'owner',
        'username' => 'suggestion-'.$suffix,
    ]);

    return [$user, $user->createToken('suggestion-test')->plainTextToken];
}

function suggestionExpense(User $user, string $name, ?string $createdAt = null, bool $deleted = false): Expense
{
    $expense = Expense::query()->create([
        'name' => $name,
        'category' => 'lainnya',
        'amount' => 1000,
        'spent_at' => '2026-10-20',
        'created_by' => $user->id,
    ]);

    if ($createdAt !== null) {
        $expense->timestamps = false;
        $expense->created_at = $createdAt;
        $expense->save();
    }

    if ($deleted) {
        $expense->delete();
    }

    return $expense;
}

it('returns up to twenty suggestions ordered by usage then recency', function () {
    [$owner, $token] = suggestionOwner('ranking');

    suggestionExpense($owner, 'Kertas', '2026-10-01 00:00:00');
    suggestionExpense($owner, 'KERTAS ', '2026-10-02 00:00:00');
    suggestionExpense($owner, 'kertas', '2026-10-03 00:00:00');
    suggestionExpense($owner, 'Gas', '2026-10-01 00:00:00');
    suggestionExpense($owner, 'GAS ', '2026-10-04 00:00:00');
    foreach (range(1, 23) as $i) {
        suggestionExpense($owner, 'Nama '.$i, sprintf('2026-10-%02d 00:00:00', min(28, $i)));
    }

    $this->withToken($token)->getJson('/api/v1/expenses/suggestions')
        ->assertOk()
        ->assertJsonCount(20, 'data')
        ->assertJsonPath('data.0', 'kertas')
        ->assertJsonPath('data.1', 'GAS ');
});

it('groups names by trimmed case-insensitive spelling and displays the latest spelling', function () {
    [$owner, $token] = suggestionOwner('grouping');
    suggestionExpense($owner, '  Listrik  ', '2026-10-01 00:00:00');
    suggestionExpense($owner, 'listrik', '2026-10-02 00:00:00');
    suggestionExpense($owner, 'LISTRIK ', '2026-10-03 00:00:00');

    $this->withToken($token)->getJson('/api/v1/expenses/suggestions')
        ->assertOk()
        ->assertExactJson(['data' => ['LISTRIK ']]);
});

it('excludes soft-deleted expenses from suggestions', function () {
    [$owner, $token] = suggestionOwner('deleted');
    suggestionExpense($owner, 'Aktif');
    suggestionExpense($owner, 'Nama terhapus', null, true);

    $this->withToken($token)->getJson('/api/v1/expenses/suggestions')
        ->assertOk()
        ->assertExactJson(['data' => ['Aktif']]);
});

it('filters suggestions by a case-insensitive escaped partial query', function () {
    [$owner, $token] = suggestionOwner('query');
    suggestionExpense($owner, 'Biaya 100% listrik');
    suggestionExpense($owner, 'Biaya 100x listrik');
    suggestionExpense($owner, 'Rak_A');
    suggestionExpense($owner, 'RakXA');

    $this->withToken($token)->getJson('/api/v1/expenses/suggestions?q=%25')
        ->assertOk()->assertExactJson(['data' => ['Biaya 100% listrik']]);
    $this->withToken($token)->getJson('/api/v1/expenses/suggestions?q=_')
        ->assertOk()->assertExactJson(['data' => ['Rak_A']]);
});

it('returns a warning for names containing kulak or bawang without rejecting creation', function () {
    [, $token] = suggestionOwner('warning-create');
    $expected = "Nama mengandung kata 'kulak' atau 'bawang'. Pembelian bawang dicatat di menu Kulak, bukan Pengeluaran.";

    foreach (['Beli BAWANG merah', 'KULAK plastik'] as $name) {
        $this->withToken($token)->postJson('/api/v1/expenses', [
            'name' => $name,
            'category' => 'lainnya',
            'amount' => 1000,
            'spent_at' => '2026-10-20',
        ])->assertCreated()->assertJsonPath('warnings.0', $expected)->assertJsonCount(1, 'warnings');
    }
});

it('returns an empty warnings array for an ordinary expense name', function () {
    [, $token] = suggestionOwner('warning-empty');

    $this->withToken($token)->postJson('/api/v1/expenses', [
        'name' => 'Listrik toko',
        'category' => 'lainnya',
        'amount' => 1000,
        'spent_at' => '2026-10-20',
    ])->assertCreated()->assertJsonPath('warnings', [])->assertJsonCount(0, 'warnings');
});

it('returns a warning when an expense is updated to a name containing the target words', function () {
    [$owner, $token] = suggestionOwner('warning-update');
    $expense = suggestionExpense($owner, 'Listrik toko');
    $expected = "Nama mengandung kata 'kulak' atau 'bawang'. Pembelian bawang dicatat di menu Kulak, bukan Pengeluaran.";

    $this->withToken($token)->putJson('/api/v1/expenses/'.$expense->id, [
        'name' => 'Biaya Bawang putih',
        'category' => 'lainnya',
        'amount' => 2000,
        'spent_at' => '2026-10-21',
    ])->assertOk()->assertJsonPath('warnings.0', $expected)->assertJsonCount(1, 'warnings');
});
