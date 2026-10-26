<?php

use App\Models\Product;
use App\Models\SaleAllocation;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Events\QueryExecuted;

uses(RefreshDatabase::class);

beforeEach(fn () => Carbon::setTestNow(Carbon::parse('2026-10-26 12:00:00', 'Asia/Jakarta')));
afterEach(fn () => Carbon::setTestNow());

function historyPrivacyUser(string $role, string $suffix): array
{
    $user = User::factory()->create([
        'role' => $role,
        'username' => $role.'-history-privacy-'.$suffix,
    ]);

    return [$user, $user->createToken('history-privacy-test')->plainTextToken];
}

function historyPrivacyTransaction(User $cashier, string $occurredAt, array $overrides = []): Transaction
{
    $product = Product::query()->firstOrCreate(
        ['name' => 'History privacy product'],
        ['price_retail' => 27500, 'price_3kg' => 26000, 'price_5kg' => 24000]
    );
    $transaction = Transaction::query()->create(array_replace([
        'invoice_no' => 'POS-PRIVACY-'.str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT),
        'client_uuid' => fake()->uuid(),
        'cashier_id' => $cashier->id,
        'customer_category' => 'ecer',
        'customer_name' => 'Bu Sari',
        'subtotal' => 75000,
        'discount' => 5000,
        'total' => 70000,
        'amount_paid' => 100000,
        'payment_method' => 'cash',
        'status' => 'completed',
        'occurred_at' => $occurredAt,
    ], $overrides));
    $item = TransactionItem::query()->create([
        'transaction_id' => $transaction->id,
        'product_id' => $product->id,
        'product_name' => $product->name,
        'weight_kg' => '2.727',
        'price_tier' => 'retail',
        'price_per_kg' => 27500,
        'line_total' => 75000,
        'cogs' => 63750,
    ]);
    SaleAllocation::query()->create([
        'transaction_item_id' => $item->id,
        'lot_id' => null,
        'weight_kg' => '2.727',
        'unit_cost' => 23400,
        'is_shortfall' => true,
    ]);

    return $transaction;
}

function historyPrivacyPayload(int $productId, string $uuid): array
{
    return [
        'client_uuid' => $uuid,
        'customer_category' => 'ecer',
        'customer_name' => 'Bu Sari',
        'payment_method' => 'cash',
        'items' => [['product_id' => $productId, 'weight_kg' => '2.727']],
        'final_total' => 70000,
        'amount_paid' => 100000,
    ];
}

it('H-12 returns the same generic 404 for old detail and receipt requests', function () {
    [$cashier, $token] = historyPrivacyUser('kasir', 'old-window');
    $old = historyPrivacyTransaction($cashier, '2026-10-24 16:59:59');

    $this->withToken($token)->getJson("/api/v1/transactions/{$old->id}")
        ->assertNotFound()->assertJsonPath('message', 'Data tidak ditemukan.');
    $this->withToken($token)->getJson("/api/v1/transactions/{$old->id}/receipt")
        ->assertNotFound()->assertJsonPath('message', 'Data tidak ditemukan.');
});

it('H-13 lets a cashier retrieve another cashier transaction from yesterday', function () {
    [$firstCashier] = historyPrivacyUser('kasir', 'yesterday-owner');
    [, $viewerToken] = historyPrivacyUser('kasir', 'yesterday-viewer');
    $yesterday = historyPrivacyTransaction($firstCashier, '2026-10-25 04:00:00');

    $this->withToken($viewerToken)->getJson("/api/v1/transactions/{$yesterday->id}")->assertOk();
    $this->withToken($viewerToken)->getJson("/api/v1/transactions/{$yesterday->id}/receipt")->assertOk();
});

it('H-35 returns all documented transaction detail and item snapshot fields', function () {
    [$cashier, $token] = historyPrivacyUser('kasir', 'detail-shape');
    $transaction = historyPrivacyTransaction($cashier, '2026-10-26 03:00:00');

    $this->withToken($token)->getJson("/api/v1/transactions/{$transaction->id}")->assertOk()
        ->assertJsonStructure(['data' => [
            'id', 'invoice_no', 'occurred_at', 'cashier_name', 'customer_name', 'customer_category', 'payment_method', 'status',
            'subtotal', 'discount', 'total', 'amount_paid', 'change', 'void_reason', 'voided_at',
            'items' => [['product_name', 'price_tier', 'weight_kg', 'price_per_kg', 'line_total']],
        ]])
        ->assertJsonPath('data.cashier_name', $cashier->name)
        ->assertJsonPath('data.amount_paid', 100000)
        ->assertJsonPath('data.change', 30000);
});

it('H-36 keeps payment fields null for legacy detail and receipt data', function () {
    [$cashier, $token] = historyPrivacyUser('kasir', 'legacy-payment');
    $legacy = historyPrivacyTransaction($cashier, '2026-10-26 03:00:00', ['amount_paid' => null]);

    $this->withToken($token)->getJson("/api/v1/transactions/{$legacy->id}")->assertOk()
        ->assertJsonPath('data.amount_paid', null)->assertJsonPath('data.change', null);
    $this->withToken($token)->getJson("/api/v1/transactions/{$legacy->id}/receipt")->assertOk()
        ->assertJsonPath('data.amount_paid', null)->assertJsonPath('data.change', null);
});

it('H-37 includes void fields only for void transactions', function () {
    [$cashier, $token] = historyPrivacyUser('kasir', 'void-fields');
    $completed = historyPrivacyTransaction($cashier, '2026-10-26 03:00:00');
    $voided = historyPrivacyTransaction($cashier, '2026-10-26 04:00:00', [
        'status' => 'void', 'void_reason' => 'Salah input', 'voided_at' => '2026-10-26 05:00:00',
    ]);

    $this->withToken($token)->getJson("/api/v1/transactions/{$completed->id}")->assertOk()
        ->assertJsonPath('data.void_reason', null)->assertJsonPath('data.voided_at', null);
    $this->withToken($token)->getJson("/api/v1/transactions/{$voided->id}")->assertOk()
        ->assertJsonPath('data.void_reason', 'Salah input')->assertJsonPath('data.voided_at', '2026-10-26T05:00:00+00:00');
});

it('H-38 recursively excludes every forbidden cost key from cashier list detail receipt create and replay JSON', function () {
    [$cashier, $token] = historyPrivacyUser('kasir', 'recursive-redaction');
    $existing = historyPrivacyTransaction($cashier, '2026-10-26 03:00:00');
    $product = Product::query()->first();
    $createdPayload = historyPrivacyPayload($product->id, 'a8f2ea20-3bb3-471b-9b8c-56dad49c75c0');

    $responses = [
        $this->withToken($token)->getJson('/api/v1/transactions?period=today')->assertOk(),
        $this->withToken($token)->getJson("/api/v1/transactions/{$existing->id}")->assertOk(),
        $this->withToken($token)->getJson("/api/v1/transactions/{$existing->id}/receipt")->assertOk(),
        $this->withToken($token)->postJson('/api/v1/transactions', $createdPayload)->assertCreated(),
        $this->withToken($token)->postJson('/api/v1/transactions', $createdPayload)->assertOk(),
    ];
    $forbidden = ['cogs', 'unit_cost', 'cost_per_clean_kg', 'sale_allocations', 'allocations', 'is_shortfall', 'lot_id', 'lots', 'purchase', 'purchases', 'hpp', 'margin', 'profit'];

    foreach ($responses as $response) {
        $found = [];
        $scan = function (mixed $value) use (&$scan, &$found, $forbidden): void {
            if (! is_array($value)) {
                return;
            }
            foreach ($value as $key => $child) {
                if (in_array((string) $key, $forbidden, true)) {
                    $found[] = (string) $key;
                }
                $scan($child);
            }
        };
        $scan($response->json());
        expect($found)->toBeEmpty();
    }
});

it('H-39 omits cogs from cashier transaction item detail', function () {
    [$cashier, $token] = historyPrivacyUser('kasir', 'item-cogs');
    $transaction = historyPrivacyTransaction($cashier, '2026-10-26 03:00:00');

    $this->withToken($token)->getJson("/api/v1/transactions/{$transaction->id}")->assertOk()
        ->assertJsonMissingPath('data.items.0.cogs');
});

it('does not select item cost columns or allocation relations for cashier response payloads', function () {
    [$cashier, $token] = historyPrivacyUser('kasir', 'no-cost-load');
    $existing = historyPrivacyTransaction($cashier, '2026-10-26 03:00:00');
    $product = Product::query()->first();
    $costReads = [];
    DB::listen(function (QueryExecuted $query) use (&$costReads): void {
        $sql = strtolower(ltrim($query->sql));
        if (str_starts_with($sql, 'select') && (
            str_contains($sql, 'sale_allocations')
            || (str_contains($sql, 'transaction_items') && preg_match('/\bcogs\b/', $sql) === 1)
        )) {
            $costReads[] = $query->sql;
        }
    });

    $this->withToken($token)->getJson("/api/v1/transactions/{$existing->id}")->assertOk();
    $this->withToken($token)->getJson("/api/v1/transactions/{$existing->id}/receipt")->assertOk();
    $payload = historyPrivacyPayload($product->id, 'b5d0a287-8a0f-4a85-a386-4b47b7cac2b0');
    $this->withToken($token)->postJson('/api/v1/transactions', $payload)->assertCreated();
    $this->withToken($token)->postJson('/api/v1/transactions', $payload)->assertOk();

    expect($costReads)->toBeEmpty();
});

it('H-40 preserves owner cogs in transaction detail and receipt', function () {
    [$cashier] = historyPrivacyUser('kasir', 'owner-source');
    [, $ownerToken] = historyPrivacyUser('owner', 'owner-view');
    $transaction = historyPrivacyTransaction($cashier, '2026-10-26 03:00:00');

    $this->withToken($ownerToken)->getJson("/api/v1/transactions/{$transaction->id}")->assertOk()
        ->assertJsonPath('data.cogs', 63750)->assertJsonPath('data.items.0.cogs', 63750);
    $this->withToken($ownerToken)->getJson("/api/v1/transactions/{$transaction->id}/receipt")->assertOk()
        ->assertJsonPath('data.cogs', 63750)->assertJsonPath('data.items.0.cogs', 63750);
});
