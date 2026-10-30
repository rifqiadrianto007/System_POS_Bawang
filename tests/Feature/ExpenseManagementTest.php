<?php

use App\Models\AuditLog;
use App\Models\Expense;
use App\Models\User;
use App\Services\ReportService;
use Carbon\Carbon;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

afterEach(function (): void {
    Carbon::setTestNow();
});

function expenseOpsUser(string $role, string $suffix): array
{
    $user = User::factory()->create([
        'role' => $role,
        'username' => $role.'-expense-ops-'.$suffix,
    ]);

    return [$user, $user->createToken('expense-ops')->plainTextToken];
}

function expenseOpsPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Listrik toko',
        'category' => 'listrik_air',
        'amount' => 50000,
        'spent_at' => '2026-10-22',
        'notes' => 'Tagihan bulan Oktober',
    ], $overrides);
}

function expenseOpsRow(User $user, array $attributes = []): Expense
{
    return Expense::query()->create(array_merge(expenseOpsPayload(), ['created_by' => $user->id], $attributes));
}

it('PG-01 accepts a valid expense and rejects an invalid or missing category', function () {
    [, $token] = expenseOpsUser('owner', 'create');
    $this->withToken($token)->postJson('/api/v1/expenses', expenseOpsPayload())
        ->assertCreated()->assertJsonPath('data.category', 'listrik_air');
    $this->withToken($token)->postJson('/api/v1/expenses', expenseOpsPayload(['category' => 'kulak']))
        ->assertUnprocessable()->assertJsonValidationErrors(['category']);
    $this->withToken($token)->postJson('/api/v1/expenses', array_diff_key(expenseOpsPayload(), ['category' => true]))
        ->assertUnprocessable()->assertJsonValidationErrors(['category']);
});

it('PG-02 returns the centralized category codes and labels and denies cashier access', function () {
    [, $ownerToken] = expenseOpsUser('owner', 'categories');
    $response = $this->withToken($ownerToken)->getJson('/api/v1/expenses/categories')->assertOk();
    expect($response->json('data'))->toBe([
        ['code' => 'kemasan', 'label' => 'Plastik dan kemasan'],
        ['code' => 'alat', 'label' => 'Alat'],
        ['code' => 'bahan_toko', 'label' => 'Bahan toko'],
        ['code' => 'upah', 'label' => 'Upah'],
        ['code' => 'listrik_air', 'label' => 'Listrik, air, pulsa'],
        ['code' => 'transportasi', 'label' => 'Transportasi'],
        ['code' => 'sewa_retribusi', 'label' => 'Sewa dan retribusi'],
        ['code' => 'lainnya', 'label' => 'Lainnya'],
    ]);

    [, $cashierToken] = expenseOpsUser('kasir', 'categories');
    $this->app['auth']->forgetGuards();
    $this->withToken($cashierToken)->getJson('/api/v1/expenses/categories')->assertForbidden();
});

it('PG-03 includes creator and category labels with a constant query count for five and thirty rows', function () {
    [$owner, $token] = expenseOpsUser('owner', 'query-count');
    foreach (range(1, 5) as $number) {
        expenseOpsRow($owner, ['name' => 'Expense '.str_pad((string) $number, 2, '0', STR_PAD_LEFT)]);
    }

    $queryCounts = [];
    $capture = false;
    $count = 0;
    DB::listen(function (QueryExecuted $query) use (&$capture, &$count): void {
        $sql = strtolower($query->sql);
        // Count list/summary and eager-load queries while excluding variable Sanctum authentication reads.
        if ($capture && (str_contains($sql, 'expenses') || (str_contains($sql, 'users') && str_contains($sql, ' in ')))) {
            $count++;
        }
    });
    $this->withToken($token)->getJson('/api/v1/expenses?per_page=50')->assertOk();
    $this->app['auth']->forgetGuards();
    $capture = true;
    $response = $this->withToken($token)->getJson('/api/v1/expenses?per_page=50')->assertOk()->assertJsonPath('data.0.created_by_name', $owner->name)
        ->assertJsonPath('data.0.category_label', 'Listrik, air, pulsa');
    $capture = false;
    $queryCounts[] = $count;

    foreach (range(6, 30) as $number) {
        expenseOpsRow($owner, ['name' => 'Expense '.str_pad((string) $number, 2, '0', STR_PAD_LEFT)]);
    }
    $count = 0;
    $this->app['auth']->forgetGuards();
    $capture = true;
    $this->withToken($token)->getJson('/api/v1/expenses?per_page=50')->assertOk()->assertJsonCount(30, 'data');
    $capture = false;
    $queryCounts[] = $count;

    expect($queryCounts[0])->toBe($queryCounts[1]);
});

it('PG-04 includes expenses exactly on both inclusive date bounds', function () {
    [$owner, $token] = expenseOpsUser('owner', 'inclusive');
    expenseOpsRow($owner, ['name' => 'Sebelum', 'spent_at' => '2026-10-20']);
    expenseOpsRow($owner, ['name' => 'Awal', 'spent_at' => '2026-10-21']);
    expenseOpsRow($owner, ['name' => 'Akhir', 'spent_at' => '2026-10-23']);
    expenseOpsRow($owner, ['name' => 'Sesudah', 'spent_at' => '2026-10-24']);
    $this->withToken($token)->getJson('/api/v1/expenses?from=2026-10-21&to=2026-10-23')
        ->assertOk()->assertJsonCount(2, 'data');
});

it('PG-05 filters categories and searches names and notes while escaping percent and underscore', function () {
    [$owner, $token] = expenseOpsUser('owner', 'search');
    expenseOpsRow($owner, ['name' => 'Biaya 10% toko', 'category' => 'alat']);
    expenseOpsRow($owner, ['name' => 'Biaya 10x toko', 'category' => 'alat']);
    expenseOpsRow($owner, ['name' => 'Catatan lain', 'notes' => 'Ada kata diskon', 'category' => 'kemasan']);
    expenseOpsRow($owner, ['name' => 'diskon di nama', 'notes' => null, 'category' => 'kemasan']);
    expenseOpsRow($owner, ['name' => 'Biaya A toko', 'category' => 'alat']);
    expenseOpsRow($owner, ['name' => 'Biaya _ toko', 'category' => 'alat']);

    $this->withToken($token)->getJson('/api/v1/expenses?category=kemasan&q=diskon')
        ->assertOk()->assertJsonCount(2, 'data');
    $this->withToken($token)->getJson('/api/v1/expenses?category=alat&q=%25')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Biaya 10% toko');
    $this->withToken($token)->getJson('/api/v1/expenses?category=alat&q=%5F')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Biaya _ toko');
});

it('PG-06 caps per_page at fifty, rejects zero and non-integers, and defaults empty per_page to twenty', function () {
    [$owner, $token] = expenseOpsUser('owner', 'pagination');
    foreach (range(1, 55) as $number) {
        expenseOpsRow($owner, ['name' => 'Baris '.$number]);
    }
    $this->withToken($token)->getJson('/api/v1/expenses?per_page=500')->assertOk()
        ->assertJsonCount(50, 'data')->assertJsonPath('meta.per_page', 50)->assertJsonPath('meta.total', 55);
    $this->withToken($token)->getJson('/api/v1/expenses?per_page=0')->assertUnprocessable()->assertJsonValidationErrors(['per_page']);
    $this->withToken($token)->getJson('/api/v1/expenses?per_page=abc')->assertUnprocessable()->assertJsonValidationErrors(['per_page']);
    $this->withToken($token)->getJson('/api/v1/expenses?per_page=')->assertOk()
        ->assertJsonCount(20, 'data')->assertJsonPath('meta.per_page', 20);
    $this->withToken($token)->getJson('/api/v1/expenses?page=2&per_page=20')->assertOk()
        ->assertJsonPath('meta.total', 55)->assertJsonPath('meta.current_page', 2)->assertJsonPath('meta.last_page', 3);
});

// Updated for docs/11 EX-08: the expenses summary now follows q as well as the date/filter range.
it('PG-07 computes summary from date category and q filters and returns integer zeros when empty', function () {
    [$owner, $token] = expenseOpsUser('owner', 'summary');
    expenseOpsRow($owner, ['name' => 'Listrik satu', 'amount' => 50000, 'category' => 'listrik_air', 'spent_at' => '2026-10-22']);
    expenseOpsRow($owner, ['name' => 'Listrik dua', 'amount' => 25000, 'category' => 'listrik_air', 'spent_at' => '2026-10-23']);
    expenseOpsRow($owner, ['name' => 'Alat', 'amount' => 100000, 'category' => 'alat', 'spent_at' => '2026-10-23']);

    $this->withToken($token)->getJson('/api/v1/expenses?from=2026-10-22&to=2026-10-23&category=listrik_air&q=satu&per_page=1')
        ->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('summary.total_amount', 50000)->assertJsonPath('summary.count', 1);
    $this->withToken($token)->getJson('/api/v1/expenses?from=2026-10-22&to=2026-10-23&category=alat&q=not-found')
        ->assertOk()->assertJsonPath('summary.total_amount', 0)->assertJsonPath('summary.count', 0);
    $this->withToken($token)->getJson('/api/v1/expenses?from=2026-10-24&to=2026-10-25')
        ->assertOk()->assertJsonPath('summary.total_amount', 0)->assertJsonPath('summary.count', 0);
});

it('PG-08 soft deletes an expense, excludes it from list summary and finance, audits full payload, and denies repeat or cashier deletion', function () {
    [$owner, $token] = expenseOpsUser('owner', 'delete');
    $expense = expenseOpsRow($owner, ['name' => 'Biaya dihapus', 'amount' => 73000, 'category' => 'transportasi']);
    $other = expenseOpsRow($owner, ['name' => 'Biaya tersisa', 'amount' => 12000]);

    $this->withToken($token)->deleteJson("/api/v1/expenses/{$expense->id}", ['reason' => 'Salah input'])
        ->assertOk()->assertExactJson(['message' => 'Pengeluaran dihapus.']);
    $expense = Expense::withTrashed()->findOrFail($expense->id);
    expect($expense->deleted_at)->not->toBeNull()->and($expense->deleted_by)->toBe($owner->id)
        ->and(Expense::query()->whereKey($expense->id)->exists())->toBeFalse();
    $audit = AuditLog::query()->where('action', 'expense.deleted')->where('entity_id', $expense->id)->firstOrFail();
    expect($audit->payload)->toMatchArray([
        'id' => $expense->id,
        'name' => 'Biaya dihapus',
        'amount' => 73000,
        'category' => 'transportasi',
        'spent_at' => '2026-10-22',
        'reason' => 'Salah input',
    ]);

    $this->withToken($token)->deleteJson("/api/v1/expenses/{$expense->id}")->assertNotFound();
    $this->withToken($token)->deleteJson("/api/v1/expenses/{$other->id}", ['reason' => str_repeat('a', 256)])
        ->assertUnprocessable()->assertJsonValidationErrors(['reason']);
    $this->withToken($token)->getJson('/api/v1/expenses?from=2026-10-01&to=2026-10-31')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('summary.total_amount', $other->amount)->assertJsonPath('summary.count', 1);
    $report = app(ReportService::class)->finance(['period' => 'daily', 'date' => '2026-10-22']);
    expect($report['operating_expenses'])->toBe(12000);

    [, $cashierToken] = expenseOpsUser('kasir', 'delete');
    $this->app['auth']->forgetGuards();
    $this->withToken($cashierToken)->getJson('/api/v1/expenses/categories')->assertForbidden();
    $this->withToken($cashierToken)->getJson('/api/v1/expenses')->assertForbidden();
    $this->withToken($cashierToken)->postJson('/api/v1/expenses', expenseOpsPayload())->assertForbidden();
    $this->withToken($cashierToken)->putJson("/api/v1/expenses/{$other->id}", expenseOpsPayload())->assertForbidden();
    $this->app['auth']->forgetGuards();
    $this->withToken($cashierToken)->deleteJson("/api/v1/expenses/{$other->id}")->assertForbidden();
});

it('PG-09 audits expense updates with complete before and after values', function () {
    [$owner, $token] = expenseOpsUser('owner', 'update-audit');
    $expense = expenseOpsRow($owner);
    $this->withToken($token)->putJson("/api/v1/expenses/{$expense->id}", expenseOpsPayload([
        'name' => 'Sewa toko', 'category' => 'sewa_retribusi', 'amount' => 75000,
        'spent_at' => '2026-10-23', 'notes' => 'Perubahan',
    ]))->assertOk();

    $audit = AuditLog::query()->where('action', 'expense.updated')->where('entity_id', $expense->id)->firstOrFail();
    expect($audit->payload['before'])->toMatchArray([
        'name' => 'Listrik toko', 'category' => 'listrik_air', 'amount' => 50000, 'spent_at' => '2026-10-22',
    ])->and($audit->payload['after'])->toMatchArray([
        'name' => 'Sewa toko', 'category' => 'sewa_retribusi', 'amount' => 75000, 'spent_at' => '2026-10-23',
    ]);
});

it('PG-10 adds a non-null category column and includes the legacy-category backfill in its migration', function () {
    [$owner] = expenseOpsUser('owner', 'legacy-category');
    $id = DB::table('expenses')->insertGetId([
        'name' => 'Legacy', 'category' => 'alat', 'amount' => 1000, 'spent_at' => '2026-10-22',
        'created_by' => $owner->id, 'created_at' => now(), 'updated_at' => now(),
    ]);
    Schema::table('expenses', function (Blueprint $table): void {
        $table->dropForeign(['deleted_by']);
        $table->dropIndex(['spent_at']);
        $table->dropIndex(['category', 'spent_at']);
        $table->dropColumn(['category', 'deleted_at', 'deleted_by']);
    });

    $migration = require database_path('migrations/2026_10_27_100000_add_categories_and_soft_deletes_to_expenses.php');
    $migration->up();

    expect(DB::table('expenses')->where('id', $id)->value('category'))->toBe('lainnya')
        ->and(Schema::hasColumn('expenses', 'deleted_at'))->toBeTrue()
        ->and(Schema::hasColumn('expenses', 'deleted_by'))->toBeTrue();
    $columns = collect(DB::select('PRAGMA table_info(expenses)'))->keyBy('name');
    expect((int) $columns['category']->notnull)->toBe(1);
    $indexes = collect(DB::select("PRAGMA index_list('expenses')"))->pluck('name');
    expect($indexes)->toContain('expenses_spent_at_index', 'expenses_category_spent_at_index');
    expect(collect(DB::select("PRAGMA foreign_key_list('expenses')"))->contains(
        fn (object $foreignKey): bool => $foreignKey->from === 'deleted_by' && $foreignKey->table === 'users'
    ))->toBeTrue();

    $migration->down();
    expect(Schema::hasColumn('expenses', 'category'))->toBeFalse()
        ->and(Schema::hasColumn('expenses', 'deleted_at'))->toBeFalse()
        ->and(Schema::hasColumn('expenses', 'deleted_by'))->toBeFalse();
});

it('PG-11 rejects zero or decimal amounts, tomorrow dates, and empty names using the frozen Jakarta date', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-27 10:00:00', 'Asia/Jakarta'));
    [, $token] = expenseOpsUser('owner', 'validation');
    foreach ([['amount' => 0], ['amount' => 50000.5], ['spent_at' => '2026-10-28'], ['name' => '']] as $invalid) {
        $this->withToken($token)->postJson('/api/v1/expenses', expenseOpsPayload($invalid))->assertUnprocessable();
    }
    $this->withToken($token)->postJson('/api/v1/expenses', expenseOpsPayload(['amount' => 0]))->assertJsonValidationErrors(['amount']);
    $this->withToken($token)->postJson('/api/v1/expenses', expenseOpsPayload(['amount' => 50000.5]))->assertJsonValidationErrors(['amount']);
    $this->withToken($token)->postJson('/api/v1/expenses', expenseOpsPayload(['spent_at' => '2026-10-28']))->assertJsonValidationErrors(['spent_at']);
    $this->withToken($token)->postJson('/api/v1/expenses', expenseOpsPayload(['name' => '']))->assertJsonValidationErrors(['name']);
});

it('PG-12 sorts by spent_at descending then id descending with stable pagination', function () {
    [$owner, $token] = expenseOpsUser('owner', 'order');
    $first = expenseOpsRow($owner, ['name' => 'Hari sama pertama', 'spent_at' => '2026-10-22']);
    $second = expenseOpsRow($owner, ['name' => 'Hari sama kedua', 'spent_at' => '2026-10-22']);
    $newer = expenseOpsRow($owner, ['name' => 'Tanggal baru', 'spent_at' => '2026-10-23']);

    $this->withToken($token)->getJson('/api/v1/expenses?per_page=2&page=1')->assertOk()
        ->assertJsonPath('data.0.id', $newer->id)->assertJsonPath('data.1.id', $second->id);
    $this->withToken($token)->getJson('/api/v1/expenses?per_page=2&page=2')->assertOk()
        ->assertJsonPath('data.0.id', $first->id);
});

it('PG-13 defaults to the first day of the current WIB month through today', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-27 00:30:00', 'Asia/Jakarta'));
    [$owner, $token] = expenseOpsUser('owner', 'default-date');
    expenseOpsRow($owner, ['name' => 'Awal bulan', 'spent_at' => '2026-10-01']);
    expenseOpsRow($owner, ['name' => 'Hari ini', 'spent_at' => '2026-10-27']);
    expenseOpsRow($owner, ['name' => 'Besok', 'spent_at' => '2026-10-28']);
    expenseOpsRow($owner, ['name' => 'Bulan lalu', 'spent_at' => '2026-09-30']);

    $this->withToken($token)->getJson('/api/v1/expenses')->assertOk()->assertJsonCount(2, 'data')
        ->assertJsonPath('summary.total_amount', 100000)->assertJsonPath('summary.count', 2);
});
