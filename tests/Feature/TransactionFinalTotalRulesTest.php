<?php

use App\Models\AuditLog;
use App\Models\Product;
use App\Models\SaleAllocation;
use App\Models\StockMovement;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

function finalTotalTestSetup(string $suffix, string $role = 'kasir'): array
{
    $user = User::factory()->create([
        'role' => $role,
        'username' => 'final-total-'.$suffix,
    ]);
    $product = Product::query()->create([
        'name' => 'Merah A '.$suffix,
        'price_retail' => 27500,
        'price_3kg' => 26000,
        'price_5kg' => 24000,
    ]);

    return [$user, $user->createToken('final-total-test')->plainTextToken, $product];
}

function finalTotalTestPayload(Product $product, array $overrides = []): array
{
    return array_replace([
        'client_uuid' => fake()->uuid(),
        'customer_category' => 'ecer',
        'items' => [['product_id' => $product->id, 'weight_kg' => '2.727']],
        'payment_method' => 'cash',
        'final_total' => 75000,
        'amount_paid' => 75000,
    ], $overrides);
}

function assertFinalTotalRejectionHasNoWrites($response, string $field, string $message): void
{
    $response->assertUnprocessable()
        ->assertJsonValidationErrors($field)
        ->assertJsonPath('errors.'.$field.'.0', $message);

    expect(Transaction::query()->count())->toBe(0)
        ->and(SaleAllocation::query()->count())->toBe(0)
        ->and(StockMovement::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0);
}

it('creates U-01 discounted cash sale with Rp30,000 change', function () {
    [, $token, $product] = finalTotalTestSetup('u01');

    $this->withToken($token)->postJson('/api/v1/transactions', finalTotalTestPayload($product, [
        'final_total' => 70000,
        'amount_paid' => 100000,
    ]))->assertCreated()
        ->assertJsonPath('data.discount', 5000)
        ->assertJsonPath('data.total', 70000)
        ->assertJsonPath('data.change', 30000);
});

it('creates U-02 exact cash payment with no change', function () {
    [, $token, $product] = finalTotalTestSetup('u02');

    $this->withToken($token)->postJson('/api/v1/transactions', finalTotalTestPayload($product))
        ->assertCreated()
        ->assertJsonPath('data.discount', 0)
        ->assertJsonPath('data.change', 0);
});

it('creates U-03 full total with Rp100,000 cash and Rp25,000 change', function () {
    [, $token, $product] = finalTotalTestSetup('u03');

    $this->withToken($token)->postJson('/api/v1/transactions', finalTotalTestPayload($product, [
        'amount_paid' => 100000,
    ]))->assertCreated()
        ->assertJsonPath('data.discount', 0)
        ->assertJsonPath('data.change', 25000);
});

it('creates U-04 discounted cash sale paid exactly', function () {
    [, $token, $product] = finalTotalTestSetup('u04');

    $this->withToken($token)->postJson('/api/v1/transactions', finalTotalTestPayload($product, [
        'final_total' => 70000,
        'amount_paid' => 70000,
    ]))->assertCreated()
        ->assertJsonPath('data.discount', 5000)
        ->assertJsonPath('data.change', 0);
});

it('rejects U-05 final total above subtotal before writing anything', function () {
    [, $token, $product] = finalTotalTestSetup('u05');

    assertFinalTotalRejectionHasNoWrites(
        $this->withToken($token)->postJson('/api/v1/transactions', finalTotalTestPayload($product, [
            'final_total' => 80000,
            'amount_paid' => 100000,
        ])),
        'final_total',
        'Total akhir tidak boleh lebih besar dari subtotal.'
    );
});

it('rejects U-06 payment below final total before writing anything', function () {
    [, $token, $product] = finalTotalTestSetup('u06');

    assertFinalTotalRejectionHasNoWrites(
        $this->withToken($token)->postJson('/api/v1/transactions', finalTotalTestPayload($product, [
            'amount_paid' => 70000,
        ])),
        'amount_paid',
        'Uang bayar kurang dari total akhir.'
    );
});

it('rejects U-07 payment one rupiah below final total before writing anything', function () {
    [, $token, $product] = finalTotalTestSetup('u07');

    assertFinalTotalRejectionHasNoWrites(
        $this->withToken($token)->postJson('/api/v1/transactions', finalTotalTestPayload($product, [
            'final_total' => 70000,
            'amount_paid' => 69999,
        ])),
        'amount_paid',
        'Uang bayar kurang dari total akhir.'
    );
});

it('rejects U-08 QRIS overpayment before writing anything', function () {
    [, $token, $product] = finalTotalTestSetup('u08');

    assertFinalTotalRejectionHasNoWrites(
        $this->withToken($token)->postJson('/api/v1/transactions', finalTotalTestPayload($product, [
            'final_total' => 70000,
            'amount_paid' => 100000,
            'payment_method' => 'qris',
        ])),
        'amount_paid',
        'Pembayaran QRIS harus sama dengan total akhir.'
    );
});

it('creates U-09 exact QRIS payment with discount', function () {
    [, $token, $product] = finalTotalTestSetup('u09');

    $this->withToken($token)->postJson('/api/v1/transactions', finalTotalTestPayload($product, [
        'final_total' => 70000,
        'amount_paid' => 70000,
        'payment_method' => 'qris',
    ]))->assertCreated()
        ->assertJsonPath('data.discount', 5000);
});

it('rejects U-10 zero final total before writing anything', function () {
    [, $token, $product] = finalTotalTestSetup('u10');

    assertFinalTotalRejectionHasNoWrites(
        $this->withToken($token)->postJson('/api/v1/transactions', finalTotalTestPayload($product, [
            'final_total' => 0,
            'amount_paid' => 100000,
        ])),
        'final_total',
        'Total akhir minimal Rp1.'
    );
});

it('rejects U-11 null final total before writing anything', function () {
    [, $token, $product] = finalTotalTestSetup('u11');

    assertFinalTotalRejectionHasNoWrites(
        $this->withToken($token)->postJson('/api/v1/transactions', finalTotalTestPayload($product, [
            'final_total' => null,
            'amount_paid' => 100000,
        ])),
        'final_total',
        'Total akhir wajib diisi.'
    );
});

it('rejects U-12 missing final total before writing anything', function () {
    [, $token, $product] = finalTotalTestSetup('u12');
    $payload = finalTotalTestPayload($product, ['amount_paid' => 100000]);
    unset($payload['final_total']);

    assertFinalTotalRejectionHasNoWrites(
        $this->withToken($token)->postJson('/api/v1/transactions', $payload),
        'final_total',
        'Total akhir wajib diisi.'
    );
});

it('rejects U-13 non-integer final totals before writing anything', function (mixed $finalTotal) {
    [, $token, $product] = finalTotalTestSetup('u13-'.md5(serialize($finalTotal)));

    $response = $this->withToken($token)->postJson('/api/v1/transactions', finalTotalTestPayload($product, [
        'final_total' => $finalTotal,
        'amount_paid' => 100000,
    ]));
    $response->assertUnprocessable()->assertJsonValidationErrors('final_total');
    expect(Transaction::query()->count())->toBe(0)
        ->and(SaleAllocation::query()->count())->toBe(0)
        ->and(StockMovement::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0);
})->with([
    'text' => ['abc'],
    'fractional' => [70000.5],
]);

it('satisfies U-27 by leaving no transaction or ledger writes for rejected payment cases', function () {
    [, $token, $product] = finalTotalTestSetup('u27');
    $invalidPayments = [
        ['final_total' => 80000, 'amount_paid' => 100000, 'field' => 'final_total', 'message' => 'Total akhir tidak boleh lebih besar dari subtotal.'],
        ['final_total' => 75000, 'amount_paid' => 70000, 'field' => 'amount_paid', 'message' => 'Uang bayar kurang dari total akhir.'],
        ['final_total' => 0, 'amount_paid' => 100000, 'field' => 'final_total', 'message' => 'Total akhir minimal Rp1.'],
        ['final_total' => null, 'amount_paid' => 100000, 'field' => 'final_total', 'message' => 'Total akhir wajib diisi.'],
        ['final_total' => 'abc', 'amount_paid' => 100000, 'field' => 'final_total', 'message' => 'Total akhir harus berupa bilangan bulat rupiah.'],
    ];

    foreach ($invalidPayments as $invalidPayment) {
        $payload = finalTotalTestPayload($product, $invalidPayment);
        unset($payload['field'], $payload['message']);

        assertFinalTotalRejectionHasNoWrites(
            $this->withToken($token)->postJson('/api/v1/transactions', $payload),
            $invalidPayment['field'],
            $invalidPayment['message']
        );
    }
});

it('creates U-14 one-rupiah final total and computes the discount on the server', function () {
    [, $token, $product] = finalTotalTestSetup('u14');

    $this->withToken($token)->postJson('/api/v1/transactions', finalTotalTestPayload($product, [
        'final_total' => 1,
        'amount_paid' => 1,
    ]))->assertCreated()
        ->assertJsonPath('data.discount', 74999)
        ->assertJsonPath('data.total', 1);
});

it('creates U-15 with a one-rupiah discount and one-rupiah change', function () {
    [, $token, $product] = finalTotalTestSetup('u15');

    $this->withToken($token)->postJson('/api/v1/transactions', finalTotalTestPayload($product, [
        'final_total' => 74999,
        'amount_paid' => 75000,
    ]))->assertCreated()
        ->assertJsonPath('data.discount', 1)
        ->assertJsonPath('data.change', 1);
});

it('ignores U-16 client discount and total values', function () {
    [, $token, $product] = finalTotalTestSetup('u16');

    $this->withToken($token)->postJson('/api/v1/transactions', finalTotalTestPayload($product, [
        'final_total' => 70000,
        'amount_paid' => 100000,
        'discount' => 99999,
        'total' => 1,
    ]))->assertCreated()
        ->assertJsonPath('data.discount', 5000)
        ->assertJsonPath('data.total', 70000);
});

it('returns U-23 change on detail, list, and receipt without the old field name', function () {
    [$cashier, $token, $product] = finalTotalTestSetup('u23');
    Carbon::setTestNow(Carbon::parse('2026-10-25 10:00:00', 'Asia/Jakarta'));
    $response = $this->withToken($token)->postJson('/api/v1/transactions', finalTotalTestPayload($product, [
        'client_uuid' => '23000000-0000-4000-8000-000000000001',
        'final_total' => 70000,
        'amount_paid' => 100000,
    ]))->assertCreated();
    $transactionId = $response->json('data.id');

    $this->withToken($token)->getJson("/api/v1/transactions/{$transactionId}")
        ->assertOk()->assertJsonPath('data.change', 30000)->assertJsonMissingPath('data.change_amount');
    $this->withToken($token)->getJson('/api/v1/transactions')
        ->assertOk()->assertJsonPath('data.0.change', 30000)->assertJsonMissingPath('data.0.change_amount');
    $this->withToken($token)->getJson("/api/v1/transactions/{$transactionId}/receipt")
        ->assertOk()->assertJsonPath('data.change', 30000)->assertJsonMissingPath('data.change_amount');

    Carbon::setTestNow();
});

it('returns U-24 payment fields from receipt identical to the creation response', function () {
    [, $token, $product] = finalTotalTestSetup('u24');
    $created = $this->withToken($token)->postJson('/api/v1/transactions', finalTotalTestPayload($product, [
        'final_total' => 70000,
        'amount_paid' => 100000,
    ]))->assertCreated();
    $transactionId = $created->json('data.id');
    $receipt = $this->withToken($token)->getJson("/api/v1/transactions/{$transactionId}/receipt")->assertOk();

    foreach (['subtotal', 'discount', 'total', 'amount_paid', 'change'] as $field) {
        expect($receipt->json('data.'.$field))->toBe($created->json('data.'.$field));
    }
});

it('returns U-25 null amount_paid and change for legacy transactions', function () {
    [$cashier, $token] = finalTotalTestSetup('u25');
    $transaction = Transaction::query()->create([
        'invoice_no' => 'POS-261025-0025',
        'client_uuid' => '25000000-0000-4000-8000-000000000025',
        'cashier_id' => $cashier->id,
        'customer_category' => 'ecer',
        'subtotal' => 75000,
        'discount' => 0,
        'total' => 75000,
        'amount_paid' => null,
        'payment_method' => 'cash',
        'status' => 'completed',
        'occurred_at' => '2026-10-25 03:00:00',
    ]);
    TransactionItem::query()->create([
        'transaction_id' => $transaction->id,
        'product_id' => Product::query()->where('name', 'Merah A u25')->value('id'),
        'product_name' => 'Merah A u25',
        'weight_kg' => '2.727',
        'price_tier' => 'retail',
        'price_per_kg' => 27500,
        'line_total' => 75000,
        'cogs' => 0,
    ]);

    $this->withToken($token)->getJson("/api/v1/transactions/{$transaction->id}")
        ->assertOk()->assertJsonPath('data.amount_paid', null)->assertJsonPath('data.change', null);
    $this->withToken($token)->getJson("/api/v1/transactions/{$transaction->id}/receipt")
        ->assertOk()->assertJsonPath('data.amount_paid', null)->assertJsonPath('data.change', null);
});

it('returns U-26 payment fields to cashiers without exposing cost fields', function () {
    [, $token, $product] = finalTotalTestSetup('u26');
    Carbon::setTestNow(Carbon::parse('2026-10-25 10:00:00', 'Asia/Jakarta'));
    $created = $this->withToken($token)->postJson('/api/v1/transactions', finalTotalTestPayload($product, [
        'amount_paid' => 100000,
    ]))->assertCreated();
    $transactionId = $created->json('data.id');

    $this->withToken($token)->getJson('/api/v1/transactions')
        ->assertOk()->assertJsonPath('data.0.amount_paid', 100000)->assertJsonPath('data.0.change', 25000)
        ->assertJsonMissingPath('data.0.cogs')->assertJsonMissingPath('data.0.items.0.cogs')
        ->assertJsonMissingPath('data.0.hpp')->assertJsonMissingPath('data.0.margin');
    $this->withToken($token)->getJson("/api/v1/transactions/{$transactionId}")
        ->assertOk()->assertJsonPath('data.amount_paid', 100000)->assertJsonPath('data.change', 25000)
        ->assertJsonMissingPath('data.cogs')->assertJsonMissingPath('data.items.0.cogs')
        ->assertJsonMissingPath('data.hpp')->assertJsonMissingPath('data.margin');
    $this->withToken($token)->getJson("/api/v1/transactions/{$transactionId}/receipt")
        ->assertOk()->assertJsonPath('data.amount_paid', 100000)->assertJsonPath('data.change', 25000)
        ->assertJsonMissingPath('data.cogs')->assertJsonMissingPath('data.items.0.cogs')
        ->assertJsonMissingPath('data.hpp')->assertJsonMissingPath('data.margin');

    Carbon::setTestNow();
});

it('satisfies U-28 by counting total and not amount_paid in finance net sales', function () {
    [, $token, $product] = finalTotalTestSetup('u28', 'owner');
    $this->withToken($token)->postJson('/api/v1/transactions', finalTotalTestPayload($product, [
        'occurred_at' => '2026-10-25T03:00:00+07:00',
        'amount_paid' => 100000,
    ]))->assertCreated();

    $this->withToken($token)->getJson('/api/v1/reports/finance?period=daily&date=2026-10-25')
        ->assertOk()->assertJsonPath('data.net_sales', 75000);
});

it('satisfies U-29 by preserving the original amount_paid on an idempotent retry', function () {
    [, $token, $product] = finalTotalTestSetup('u29');
    $uuid = '29000000-0000-4000-8000-000000000029';
    $first = $this->withToken($token)->postJson('/api/v1/transactions', finalTotalTestPayload($product, [
        'client_uuid' => $uuid,
        'final_total' => 70000,
        'amount_paid' => 100000,
    ]))->assertCreated();
    $retry = $this->withToken($token)->postJson('/api/v1/transactions', finalTotalTestPayload($product, [
        'client_uuid' => $uuid,
        'final_total' => 70000,
        'amount_paid' => 120000,
    ]))->assertOk();

    expect(Transaction::query()->where('client_uuid', $uuid)->count())->toBe(1)
        ->and($retry->json('data.id'))->toBe($first->json('data.id'))
        ->and($retry->json('data.amount_paid'))->toBe(100000)
        ->and($retry->json('data.change'))->toBe(30000);
});

it('satisfies U-30 by auditing discounts and leaving full-price sales unaudited', function () {
    [, $token, $product] = finalTotalTestSetup('u30');
    $this->withToken($token)->postJson('/api/v1/transactions', finalTotalTestPayload($product, [
        'client_uuid' => '30000000-0000-4000-8000-000000000030',
        'final_total' => 70000,
        'amount_paid' => 100000,
    ]))->assertCreated();
    $this->withToken($token)->postJson('/api/v1/transactions', finalTotalTestPayload($product, [
        'client_uuid' => '30000000-0000-4000-8000-000000000031',
        'final_total' => 75000,
        'amount_paid' => 75000,
    ]))->assertCreated();

    $audit = AuditLog::query()->where('action', 'transaction.discount')->sole();
    expect(AuditLog::query()->where('action', 'transaction.discount')->count())->toBe(1)
        ->and($audit->payload)->toBe([
            'subtotal' => 75000,
            'final_total' => 70000,
            'amount_paid' => 100000,
            'discount' => 5000,
        ]);
});

it('satisfies U-31 by retaining amount_paid and change after void while excluding the sale', function () {
    [, $token, $product] = finalTotalTestSetup('u31', 'owner');
    $created = $this->withToken($token)->postJson('/api/v1/transactions', finalTotalTestPayload($product, [
        'final_total' => 70000,
        'amount_paid' => 100000,
        'occurred_at' => '2026-10-25T03:00:00+07:00',
    ]))->assertCreated();
    $transactionId = $created->json('data.id');

    $this->withToken($token)->postJson("/api/v1/transactions/{$transactionId}/void", [
        'reason' => 'U-31 regression',
    ])->assertOk();
    $this->withToken($token)->getJson("/api/v1/transactions/{$transactionId}")
        ->assertOk()->assertJsonPath('data.amount_paid', 100000)->assertJsonPath('data.change', 30000);
    $this->withToken($token)->getJson('/api/v1/reports/finance?period=daily&date=2026-10-25')
        ->assertOk()->assertJsonPath('data.net_sales', 0)->assertJsonPath('data.void_count', 1);
});
