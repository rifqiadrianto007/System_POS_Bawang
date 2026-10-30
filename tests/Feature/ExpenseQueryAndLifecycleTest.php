<?php

use App\Models\AuditLog;
use App\Models\Expense;
use App\Models\User;
use App\Services\ReportService;
use Carbon\Carbon;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

afterEach(function (): void {
    Carbon::setTestNow();
});

function queryExpenseUser(string $role, string $suffix): array
{
    $user = User::factory()->create([
        'role' => $role,
        'username' => $role.'-query-expense-'.$suffix,
    ]);
    return [$user, $user->createToken('query-expense-test')->plainTextToken];
}

function queryExpensePayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Biaya contoh', 'category' => 'lainnya', 'amount' => 10000,
        'spent_at' => '2026-10-22', 'notes' => null,
    ], $overrides);
}

function queryExpenseRow(User $owner, array $overrides = []): Expense
{
    return Expense::query()->create(array_merge(queryExpensePayload(), ['created_by' => $owner->id], $overrides));
}

it('EX-07 searches name and notes case-insensitively and treats percent and underscore literally', function () {
    [$owner, $token] = queryExpenseUser('owner', 'search');
    queryExpenseRow($owner, ['name' => 'Listrik Bulan Ini', 'amount' => 12000]);
    queryExpenseRow($owner, ['name' => 'Biaya rutin', 'notes' => 'TAGIHAN LISTRIK', 'amount' => 23000]);
    queryExpenseRow($owner, ['name' => 'Kemasan 10% toko', 'amount' => 1000]);
    queryExpenseRow($owner, ['name' => 'Kemasan 10x toko', 'amount' => 2000]);
    queryExpenseRow($owner, ['name' => 'Barang_A', 'amount' => 3000]);
    queryExpenseRow($owner, ['name' => 'BarangXA', 'amount' => 4000]);

    $this->withToken($token)->getJson('/api/v1/expenses?q=LiStRiK&from=2026-10-01&to=2026-10-31')
        ->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('summary.total_amount', 35000)
        ->assertJsonPath('summary.count', 2);
    $this->withToken($token)->getJson('/api/v1/expenses?q=%25&from=2026-10-01&to=2026-10-31')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Kemasan 10% toko');
    $this->withToken($token)->getJson('/api/v1/expenses?q=%5F&from=2026-10-01&to=2026-10-31')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Barang_A');
    $this->withToken($token)->getJson('/api/v1/expenses?q='.str_repeat('a', 51))
        ->assertUnprocessable()->assertJsonValidationErrors(['q']);
});

it('EX-08 calculates summary from all rows matching q rather than only the current page', function () {
    [$owner, $token] = queryExpenseUser('owner', 'summary-q');
    queryExpenseRow($owner, ['name' => 'Listrik pagi', 'amount' => 11000]);
    queryExpenseRow($owner, ['name' => 'Listrik sore', 'amount' => 22000]);
    queryExpenseRow($owner, ['name' => 'Transportasi', 'amount' => 33000]);

    $this->withToken($token)->getJson('/api/v1/expenses?q=listrik&per_page=1&page=1')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 2)
        ->assertJsonPath('summary.total_amount', 33000)->assertJsonPath('summary.count', 2);
});

it('EX-09 validates inclusive date bounds, paired dates, order, and the 366-day limit', function () {
    [$owner, $token] = queryExpenseUser('owner', 'bounds');
    queryExpenseRow($owner, ['name' => 'Batas awal', 'spent_at' => '2026-10-01']);
    queryExpenseRow($owner, ['name' => 'Batas akhir', 'spent_at' => '2026-10-31']);
    queryExpenseRow($owner, ['name' => 'Sebelum', 'spent_at' => '2026-09-30']);
    queryExpenseRow($owner, ['name' => 'Sesudah', 'spent_at' => '2026-11-01']);

    $this->withToken($token)->getJson('/api/v1/expenses?from=2026-10-01&to=2026-10-31')
        ->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('summary.count', 2);
    $this->withToken($token)->getJson('/api/v1/expenses?from=2026-10-01')->assertUnprocessable()->assertJsonValidationErrors(['to']);
    $this->withToken($token)->getJson('/api/v1/expenses?to=2026-10-31')->assertUnprocessable()->assertJsonValidationErrors(['from']);
    $this->withToken($token)->getJson('/api/v1/expenses?from=2026-10-31&to=2026-10-01')->assertUnprocessable()->assertJsonValidationErrors(['to']);
    $this->withToken($token)->getJson('/api/v1/expenses?from=2025-10-01&to=2026-10-01')->assertOk();
    $this->withToken($token)->getJson('/api/v1/expenses?from=2025-09-30&to=2026-10-01')->assertUnprocessable()->assertJsonValidationErrors(['to']);
});

it('EX-10 clamps per_page above fifty and returns standard pagination metadata', function () {
    [$owner, $token] = queryExpenseUser('owner', 'pagination');
    foreach (range(1, 55) as $number) {
        queryExpenseRow($owner, ['name' => 'Baris '.$number]);
    }
    $this->withToken($token)->getJson('/api/v1/expenses?per_page=500')
        ->assertOk()->assertJsonCount(50, 'data')->assertJsonPath('meta.total', 55)
        ->assertJsonPath('meta.current_page', 1)->assertJsonPath('meta.last_page', 2)->assertJsonPath('meta.per_page', 50);
    $this->withToken($token)->getJson('/api/v1/expenses?per_page=0')->assertUnprocessable()->assertJsonValidationErrors(['per_page']);
    $this->withToken($token)->getJson('/api/v1/expenses?per_page=abc')->assertUnprocessable()->assertJsonValidationErrors(['per_page']);
    $this->withToken($token)->getJson('/api/v1/expenses?per_page=')->assertOk()->assertJsonPath('meta.per_page', 20);
});

it('EX-11 orders by spent_at descending and then id descending across pages', function () {
    [$owner, $token] = queryExpenseUser('owner', 'ordering');
    $older = queryExpenseRow($owner, ['name' => 'Tanggal lama', 'spent_at' => '2026-10-21']);
    $sameDayFirst = queryExpenseRow($owner, ['name' => 'Hari sama pertama', 'spent_at' => '2026-10-22']);
    $sameDaySecond = queryExpenseRow($owner, ['name' => 'Hari sama kedua', 'spent_at' => '2026-10-22']);
    $newer = queryExpenseRow($owner, ['name' => 'Tanggal baru', 'spent_at' => '2026-10-23']);

    $this->withToken($token)->getJson('/api/v1/expenses?per_page=2&page=1')
        ->assertOk()->assertJsonPath('data.0.id', $newer->id)->assertJsonPath('data.1.id', $sameDaySecond->id);
    $this->withToken($token)->getJson('/api/v1/expenses?per_page=2&page=2')
        ->assertOk()->assertJsonPath('data.0.id', $sameDayFirst->id)->assertJsonPath('data.1.id', $older->id);
});

it('EX-12 eager loads creator names with the same number of list queries for five and thirty rows', function () {
    [$owner, $token] = queryExpenseUser('owner', 'constant-queries');
    foreach (range(1, 5) as $number) {
        queryExpenseRow($owner, ['name' => 'Expense '.$number]);
    }
    $capture = false;
    $count = 0;
    $queryCounts = [];
    DB::listen(function (QueryExecuted $query) use (&$capture, &$count): void {
        if ($capture && str_contains(strtolower($query->sql), 'expenses')) {
            $count++;
        }
    });

    $this->withToken($token)->getJson('/api/v1/expenses?from=2026-10-01&to=2026-10-31')->assertOk();
    $this->app['auth']->forgetGuards();
    $count = 0;
    $capture = true;
    $this->withToken($token)->getJson('/api/v1/expenses?from=2026-10-01&to=2026-10-31')
        ->assertOk()->assertJsonPath('data.0.created_by_name', $owner->name);
    $capture = false;
    $queryCounts[] = $count;

    foreach (range(6, 30) as $number) {
        queryExpenseRow($owner, ['name' => 'Expense '.$number]);
    }
    $count = 0;
    $this->app['auth']->forgetGuards();
    $capture = true;
    $this->withToken($token)->getJson('/api/v1/expenses?from=2026-10-01&to=2026-10-31')
        ->assertOk()->assertJsonPath('data.0.created_by_name', $owner->name);
    $capture = false;
    $queryCounts[] = $count;

    expect($queryCounts[0])->toBe($queryCounts[1]);
});

it('EX-13 soft deletes with audit and excludes deleted expenses from list and finance', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-27 10:00:00', 'Asia/Jakarta'));
    [$owner, $token] = queryExpenseUser('owner', 'soft-delete');
    $deleted = queryExpenseRow($owner, ['name' => 'Hapus ini', 'notes' => 'Catatan lama', 'amount' => 73000]);
    $remaining = queryExpenseRow($owner, ['name' => 'Tetap ada', 'amount' => 12000]);

    $this->withToken($token)->deleteJson('/api/v1/expenses/'.$deleted->id, ['reason' => 'Salah input'])
        ->assertOk()->assertExactJson(['message' => 'Pengeluaran dihapus.']);
    $stored = Expense::withTrashed()->findOrFail($deleted->id);
    expect($stored->deleted_at)->not->toBeNull()->and($stored->deleted_by)->toBe($owner->id);
    $this->withToken($token)->getJson('/api/v1/expenses?from=2026-10-01&to=2026-10-31')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('summary.total_amount', $remaining->amount)
        ->assertJsonPath('summary.count', 1);
    $this->withToken($token)->putJson('/api/v1/expenses/'.$deleted->id, queryExpensePayload())->assertNotFound();
    $this->withToken($token)->deleteJson('/api/v1/expenses/'.$deleted->id)->assertNotFound();

    $audit = AuditLog::query()->where('action', 'expense.deleted')->where('entity_id', $deleted->id)->firstOrFail();
    expect($audit->payload)->toMatchArray([
        'id' => $deleted->id, 'name' => 'Hapus ini', 'notes' => 'Catatan lama', 'amount' => 73000,
        'spent_at' => '2026-10-22', 'user' => ['id' => $owner->id], 'reason' => 'Salah input',
    ]);
    $report = app(ReportService::class)->finance(['period' => 'daily', 'date' => '2026-10-22']);
    expect($report['operating_expenses'])->toBe(12000);
});

it('EX-14 updates an expense and records complete before and after audit snapshots', function () {
    [$owner, $token] = queryExpenseUser('owner', 'update-audit');
    $expense = queryExpenseRow($owner);
    $this->withToken($token)->putJson('/api/v1/expenses/'.$expense->id, queryExpensePayload([
        'name' => 'Sewa toko', 'amount' => 75000, 'spent_at' => '2026-10-23', 'notes' => 'Ubah catatan',
    ]))->assertOk()->assertJsonPath('data.name', 'Sewa toko');

    $audit = AuditLog::query()->where('action', 'expense.updated')->where('entity_id', $expense->id)->firstOrFail();
    expect($audit->payload['before'])->toMatchArray(['name' => 'Biaya contoh', 'amount' => 10000, 'spent_at' => '2026-10-22'])
        ->and($audit->payload['after'])->toMatchArray(['name' => 'Sewa toko', 'amount' => 75000, 'spent_at' => '2026-10-23']);
});

it('EX-15 allows only owners on expense list create update and delete endpoints', function () {
    [$owner] = queryExpenseUser('owner', 'roles');
    $expense = queryExpenseRow($owner);
    [, $cashierToken] = queryExpenseUser('kasir', 'roles');
    $payload = queryExpensePayload();
    $this->withToken($cashierToken)->getJson('/api/v1/expenses')->assertForbidden();
    $this->withToken($cashierToken)->postJson('/api/v1/expenses', $payload)->assertForbidden();
    $this->withToken($cashierToken)->putJson('/api/v1/expenses/'.$expense->id, $payload)->assertForbidden();
    $this->withToken($cashierToken)->deleteJson('/api/v1/expenses/'.$expense->id)->assertForbidden();
    $this->app['auth']->forgetGuards();
    $this->withHeaders(['Authorization' => ''])->getJson('/api/v1/expenses')->assertUnauthorized();
});

it('EX-16 keeps purchases and cogs stable while an expense changes finance totals by one hundred thousand', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-27 10:00:00', 'Asia/Jakarta'));
    [$owner, $token] = queryExpenseUser('owner', 'finance-delta');
    $before = app(ReportService::class)->finance(['period' => 'daily', 'date' => '2026-10-22']);
    $this->withToken($token)->postJson('/api/v1/expenses', queryExpensePayload([
        'name' => 'Biaya tambahan', 'amount' => 100000, 'spent_at' => '2026-10-22',
    ]))->assertCreated();
    $after = app(ReportService::class)->finance(['period' => 'daily', 'date' => '2026-10-22']);

    expect($after['purchases_total'])->toBe($before['purchases_total'])
        ->and($after['cogs'])->toBe($before['cogs'])
        ->and($after['gross_profit'])->toBe($before['gross_profit'])
        ->and($after['operating_expenses'] - $before['operating_expenses'])->toBe(100000)
        ->and($after['net_profit'] - $before['net_profit'])->toBe(-100000)
        ->and($after['cash_flow'] - $before['cash_flow'])->toBe(-100000)
        ->and($after['total_spending'])->toBe($after['operating_expenses'] + $after['purchases_total']);
});

it('EX-19 defaults to the current WIB month through today', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-27 00:30:00', 'Asia/Jakarta'));
    [$owner, $token] = queryExpenseUser('owner', 'default-period');
    queryExpenseRow($owner, ['name' => 'Bulan lalu', 'spent_at' => '2026-09-30']);
    queryExpenseRow($owner, ['name' => 'Awal bulan', 'spent_at' => '2026-10-01']);
    queryExpenseRow($owner, ['name' => 'Hari ini', 'spent_at' => '2026-10-27']);
    queryExpenseRow($owner, ['name' => 'Besok', 'spent_at' => '2026-10-28']);

    $this->withToken($token)->getJson('/api/v1/expenses')->assertOk()->assertJsonCount(2, 'data')
        ->assertJsonPath('summary.count', 2)->assertJsonPath('summary.total_amount', 20000);
});

it('EX-20 wraps create and update data with a warnings array', function () {
    [$owner, $token] = queryExpenseUser('owner', 'response-envelope');
    $created = $this->withToken($token)->postJson('/api/v1/expenses', queryExpensePayload())
        ->assertCreated()->assertJsonPath('data.name', 'Biaya contoh')->assertJsonPath('warnings', [])->json('data.id');
    $this->withToken($token)->putJson('/api/v1/expenses/'.$created, queryExpensePayload(['name' => 'Biaya berubah']))
        ->assertOk()->assertJsonPath('data.name', 'Biaya berubah')->assertJsonPath('warnings', []);
});
