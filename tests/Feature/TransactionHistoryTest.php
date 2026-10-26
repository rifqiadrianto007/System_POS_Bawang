<?php

use App\Models\Product;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

function historyUser(string $role, string $suffix): array
{
    $user = User::factory()->create(['role' => $role, 'username' => $role.'-'.$suffix]);

    return [$user, $user->createToken('history-test')->plainTextToken];
}

function historyTransaction(User $cashier, string $invoice, string $occurredAt, string $status = 'completed', string $weight = '0.250'): Transaction
{
    $transaction = Transaction::query()->create([
        'invoice_no' => $invoice,
        'client_uuid' => fake()->uuid(),
        'cashier_id' => $cashier->id,
        'customer_category' => 'ecer',
        'customer_name' => 'Bu Sari',
        'subtotal' => 75000,
        'discount' => 5000,
        'total' => 70000,
        'amount_paid' => 100000,
        'payment_method' => 'cash',
        'status' => $status,
        'occurred_at' => $occurredAt,
    ]);

    $product = Product::query()->firstOrCreate(
        ['name' => 'Merah A'],
        ['price_retail' => 27500, 'price_3kg' => 26000, 'price_5kg' => 24000]
    );

    $transaction->items()->create([
        'product_id' => $product->id,
        'product_name' => 'Merah A',
        'weight_kg' => $weight,
        'price_tier' => 'retail',
        'price_per_kg' => 27500,
        'line_total' => 6900,
        'cogs' => 0,
    ]);

    return $transaction;
}

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-26 08:00:00', 'Asia/Jakarta'));
});

afterEach(function () {
    Carbon::setTestNow();
});

it('H-01 and H-06 classifies the 05:30 WIB sale as today while storing its UTC timestamp', function () {
    [$cashier, $token] = historyUser('kasir', 'morning');
    historyTransaction($cashier, 'POS-261026-0001', '2026-10-25 22:30:00');

    $this->withToken($token)->getJson('/api/v1/transactions?period=today')->assertOk()
        ->assertJsonPath('data.0.invoice_no', 'POS-261026-0001');
    expect(DB::table('transactions')->value('occurred_at'))->toContain('2026-10-25 22:30:00');
});

it('H-02 includes a transaction at 23:59 WIB in today', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-26 23:59:30', 'Asia/Jakarta'));
    [$cashier, $token] = historyUser('kasir', 'late');
    historyTransaction($cashier, 'POS-261026-0002', '2026-10-26 16:59:00');

    $this->withToken($token)->getJson('/api/v1/transactions?period=today')->assertOk()->assertJsonCount(1, 'data');
});

it('H-03 separates midnight today and the last second of yesterday', function () {
    [$cashier, $token] = historyUser('kasir', 'midnight');
    historyTransaction($cashier, 'POS-261026-0003', '2026-10-25 17:00:00');
    historyTransaction($cashier, 'POS-261025-0004', '2026-10-25 16:59:59');

    $this->withToken($token)->getJson('/api/v1/transactions?period=today')->assertOk()->assertJsonCount(1, 'data');
    $this->withToken($token)->getJson('/api/v1/transactions?period=yesterday')->assertOk()->assertJsonCount(1, 'data');
});

it('H-04 puts a 23:50 transaction in yesterday when now is 00:30 WIB', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-26 00:30:00', 'Asia/Jakarta'));
    [$cashier, $token] = historyUser('kasir', 'half-past');
    historyTransaction($cashier, 'POS-261025-0005', '2026-10-25 16:50:00');

    $this->withToken($token)->getJson('/api/v1/transactions?period=yesterday')->assertOk()->assertJsonCount(1, 'data');
    $this->withToken($token)->getJson('/api/v1/transactions?period=today')->assertOk()->assertJsonCount(0, 'data');
});

it('H-07 and H-09 rejects cashier dates outside the two-day window, while H-08 accepts its edges', function () {
    [$cashier, $token] = historyUser('kasir', 'window');
    $this->withToken($token)->getJson('/api/v1/transactions?from=2026-10-23')
        ->assertUnprocessable()->assertJsonPath('message', 'Kasir hanya dapat melihat transaksi hari ini dan kemarin.');
    $this->withToken($token)->getJson('/api/v1/transactions?to=2026-10-27')
        ->assertUnprocessable()->assertJsonPath('message', 'Kasir hanya dapat melihat transaksi hari ini dan kemarin.');
    $this->withToken($token)->getJson('/api/v1/transactions?from=2026-10-25&to=2026-10-26')->assertOk();
});

it('H-10 and H-11 validates period values and disallows period with from or to', function () {
    [$owner, $token] = historyUser('owner', 'period-validation');
    $this->withToken($token)->getJson('/api/v1/transactions?period=lastweek')->assertUnprocessable()->assertJsonValidationErrors('period');
    $this->withToken($token)->getJson('/api/v1/transactions?period=today&from=2026-10-26')->assertUnprocessable()->assertJsonValidationErrors('period');
    $this->withToken($token)->getJson('/api/v1/transactions?period=yesterday&to=2026-10-26')->assertUnprocessable()->assertJsonValidationErrors('period');
});

it('H-15 defaults to today and H-16 lets a cashier see transactions from every cashier', function () {
    [$cashierA, $token] = historyUser('kasir', 'a');
    [$cashierB] = historyUser('kasir', 'b');
    historyTransaction($cashierB, 'POS-261026-0016', '2026-10-25 22:30:00');

    $this->withToken($token)->getJson('/api/v1/transactions')->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.invoice_no', 'POS-261026-0016');
});

it('H-17 requires authentication for the transaction history endpoint', function () {
    $this->getJson('/api/v1/transactions')->assertUnauthorized();
});

it('H-18 orders equal timestamps by descending id without duplicates across pages', function () {
    [$owner, $token] = historyUser('owner', 'tie-order');
    $first = historyTransaction($owner, 'POS-TIE-0001', '2026-10-26 01:00:00');
    $second = historyTransaction($owner, 'POS-TIE-0002', '2026-10-26 01:00:00');

    $this->withToken($token)->getJson('/api/v1/transactions?period=today&per_page=1&page=1')->assertOk()
        ->assertJsonPath('data.0.id', $second->id);
    $this->withToken($token)->getJson('/api/v1/transactions?period=today&per_page=1&page=2')->assertOk()
        ->assertJsonPath('data.0.id', $first->id);
});

it('H-19 finds partial invoice numbers and H-20 treats percent and underscore literally', function () {
    [$owner, $token] = historyUser('owner', 'search');
    historyTransaction($owner, 'POS-261026-0001', '2026-10-26 01:00:00');
    historyTransaction($owner, 'POS-261026-0002', '2026-10-26 02:00:00');

    $this->withToken($token)->getJson('/api/v1/transactions?period=today&q=0001')->assertOk()->assertJsonCount(1, 'data');
    $this->withToken($token)->getJson('/api/v1/transactions?period=today&q=%25')->assertOk()->assertJsonCount(0, 'data');
    $this->withToken($token)->getJson('/api/v1/transactions?period=today&q=_')->assertOk()->assertJsonCount(0, 'data');
});

it('H-21 rejects a search longer than 30 characters', function () {
    [$owner, $token] = historyUser('owner', 'long-search');
    $this->withToken($token)->getJson('/api/v1/transactions?q='.str_repeat('a', 31))->assertUnprocessable()->assertJsonValidationErrors('q');
});

it('H-22 caps page size at 50, and H-23 validates page sizes and defaults to 20', function () {
    [$owner, $token] = historyUser('owner', 'page-size');
    $this->withToken($token)->getJson('/api/v1/transactions?per_page=500')->assertOk()->assertJsonPath('meta.per_page', 50);
    $this->withToken($token)->getJson('/api/v1/transactions?per_page=0')->assertUnprocessable()->assertJsonValidationErrors('per_page');
    $this->withToken($token)->getJson('/api/v1/transactions?per_page=abc')->assertUnprocessable()->assertJsonValidationErrors('per_page');
    $this->withToken($token)->getJson('/api/v1/transactions')->assertOk()->assertJsonPath('meta.per_page', 20);
});

it('H-24 returns pagination metadata and H-25 returns exactly the documented row keys', function () {
    [$owner, $token] = historyUser('owner', 'row-shape');
    historyTransaction($owner, 'POS-261026-0024', '2026-10-26 01:00:00');
    $response = $this->withToken($token)->getJson('/api/v1/transactions?period=today')->assertOk();
    expect(array_keys($response->json('data.0')))->toBe([
        'id', 'invoice_no', 'occurred_at', 'cashier_name', 'items_count', 'total_weight_kg', 'subtotal', 'discount', 'total', 'payment_method', 'status', 'customer_name',
    ]);
    expect(array_keys($response->json('meta')))->toBe(['total', 'current_page', 'last_page', 'per_page']);
});

it('H-26 sums item weight and formats it as a three-decimal string', function () {
    [$owner, $token] = historyUser('owner', 'weight');
    $transaction = historyTransaction($owner, 'POS-261026-0026', '2026-10-26 01:00:00', 'completed', '0.350');
    $transaction->items()->create([
        'product_id' => Product::query()->first()->id, 'product_name' => 'Merah A', 'weight_kg' => '0.250',
        'price_tier' => 'retail', 'price_per_kg' => 27500, 'line_total' => 6900, 'cogs' => 0,
    ]);
    $this->withToken($token)->getJson('/api/v1/transactions?period=today')->assertOk()->assertJsonPath('data.0.total_weight_kg', '0.600');
});

it('H-27 keeps query count fixed as the number of transactions grows', function () {
    [$owner, $token] = historyUser('owner', 'query-count');
    for ($i = 1; $i <= 30; $i++) {
        historyTransaction($owner, sprintf('POS-Q-%04d', $i), '2026-10-26 01:00:00');
    }
    $countSelects = function (int $perPage) use ($token): int {
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            if (str_starts_with(strtolower(ltrim($query->sql)), 'select') && str_contains(strtolower($query->sql), 'transactions')) {
                $queries[] = $query->sql;
            }
        });
        $this->withToken($token)->getJson('/api/v1/transactions?period=today&per_page='.$perPage)->assertOk();

        return count($queries);
    };
    $five = $countSelects(5);
    $thirty = $countSelects(30);
    expect($five)->toBe($thirty);
});

it('H-28 keeps void rows in the list', function () {
    [$owner, $token] = historyUser('owner', 'void-row');
    historyTransaction($owner, 'POS-261026-0028', '2026-10-26 01:00:00', 'void');
    $this->withToken($token)->getJson('/api/v1/transactions?period=today')->assertOk()->assertJsonPath('data.0.status', 'void');
});

it('H-29 summarizes the whole period independent of page, H-30 excludes voids, and H-31 preserves the sum invariant', function () {
    [$owner, $token] = historyUser('owner', 'summary');
    for ($i = 1; $i <= 25; $i++) {
        historyTransaction($owner, sprintf('POS-S-%04d', $i), '2026-10-26 01:00:00');
    }
    historyTransaction($owner, 'POS-S-VOID', '2026-10-26 01:00:00', 'void');
    $summary = $this->withToken($token)->getJson('/api/v1/transactions?period=today&per_page=10&page=2')->assertOk()->json('summary');
    expect($summary['transactions_count'])->toBe(25)->and($summary['voided_count'])->toBe(1)
        ->and($summary['subtotal_sum'])->toBe(1875000)->and($summary['discount_sum'])->toBe(125000)
        ->and($summary['total_sum'])->toBe(1750000)
        ->and($summary['total_sum'])->toBe($summary['subtotal_sum'] - $summary['discount_sum']);
});

it('H-32 returns integer zeros for empty periods', function () {
    [$owner, $token] = historyUser('owner', 'empty-summary');
    $summary = $this->withToken($token)->getJson('/api/v1/transactions?period=today')->assertOk()->json('summary');
    expect($summary)->toBe(['transactions_count' => 0, 'voided_count' => 0, 'subtotal_sum' => 0, 'discount_sum' => 0, 'total_sum' => 0]);
    foreach ($summary as $value) expect($value)->toBeInt();
});

it('H-33 leaves summary unchanged when q filters list rows', function () {
    [$owner, $token] = historyUser('owner', 'summary-search');
    historyTransaction($owner, 'POS-SUMMARY-0001', '2026-10-26 01:00:00');
    historyTransaction($owner, 'POS-SUMMARY-0002', '2026-10-26 02:00:00');
    $all = $this->withToken($token)->getJson('/api/v1/transactions?period=today')->assertOk()->json('summary');
    $searched = $this->withToken($token)->getJson('/api/v1/transactions?period=today&q=0001')->assertOk()->json('summary');
    expect($searched)->toBe($all);
});

it('H-34 summarizes only yesterday when period=yesterday', function () {
    [$owner, $token] = historyUser('owner', 'yesterday-summary');
    historyTransaction($owner, 'POS-261025-0034', '2026-10-25 16:00:00');
    historyTransaction($owner, 'POS-261026-0034', '2026-10-25 17:00:00');
    $summary = $this->withToken($token)->getJson('/api/v1/transactions?period=yesterday')->assertOk()->json('summary');
    expect($summary['transactions_count'])->toBe(1)->and($summary['subtotal_sum'])->toBe(75000);
});

it('H-41 retains the occurred_at index and unique invoice number index', function () {
    expect(Schema::hasIndex('transactions', ['occurred_at']))->toBeTrue()
        ->and(Schema::hasIndex('transactions', ['invoice_no'], 'unique'))->toBeTrue();
});
