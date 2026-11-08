<?php

use App\Models\AuditLog;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Services\AverageCostService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function v2User(): array
{
    $user = User::factory()->create(['role' => 'owner', 'username' => 'owner-v2-test']);

    return [$user, $user->createToken('v2-test')->plainTextToken];
}

function v2Product(string $name = 'Merah V2'): Product
{
    return Product::query()->create([
        'name' => $name, 'jenis' => 'merah', 'price_retail' => 27500,
        'price_5kg' => 24000, 'is_active' => true,
    ]);
}

function v2PurchasePayload(int $supplierId, string $weight = '100.000'): array
{
    return ['supplier_id' => $supplierId, 'purchased_at' => '2026-10-22', 'jenis' => 'merah',
        'gross_weight_kg' => $weight, 'price_per_kg' => 20500, 'notes' => 'uji v2'];
}

function v2CreatePurchase(int $supplierId, string $weight = '100.000'): Purchase
{
    $sequence = Purchase::withTrashed()->count() + 1;

    return Purchase::query()->create([...v2PurchasePayload($supplierId, $weight), 'code' => 'KLK-261022-'.str_pad((string) $sequence, 3, '0', STR_PAD_LEFT),
        'total_cost' => (int) bcmul($weight, '20500', 0), 'created_by' => User::query()->where('username', 'owner-v2-test')->value('id')]);
}

function v2LotPayload(int $productId, string $weight = '20.000', string $date = '2026-10-22'): array
{
    return ['product_id' => $productId, 'weight_kg' => $weight, 'sorted_at' => $date, 'notes' => 'sortir'];
}

it('PF-01 assigns unique daily purchase codes without changing stock', function () {
    [$owner, $token] = v2User();
    $supplier = Supplier::query()->create(['name' => 'Supplier v2']);
    $before = StockMovement::query()->count();
    $first = $this->withToken($token)->postJson('/api/v1/purchases', v2PurchasePayload($supplier->id))->assertCreated();
    $second = $this->withToken($token)->postJson('/api/v1/purchases', v2PurchasePayload($supplier->id))->assertCreated();
    expect($first->json('data.code'))->toBe('KLK-261022-001')
        ->and($second->json('data.code'))->toBe('KLK-261022-002')
        ->and(StockMovement::query()->count())->toBe($before);
    expect($first->json('data'))->not->toHaveKey('status')->not->toHaveKey('finalized_at');
});

it('PF-02 adds sorted weight and purchase value to product ledger immediately', function () {
    [$owner, $token] = v2User();
    $supplier = Supplier::query()->create(['name' => 'Supplier v2']);
    $product = v2Product();
    $purchase = v2CreatePurchase($supplier->id);
    $this->withToken($token)->postJson("/api/v1/purchases/{$purchase->id}/lots", v2LotPayload($product->id))->assertCreated();
    expect(app(AverageCostService::class)->stock($product)['stock_kg'])->toBe('20.000')
        ->and((int) StockMovement::query()->where('product_id', $product->id)->sum('value_delta'))->toBe(410000)
        ->and(StockMovement::query()->where('type', 'in_sort')->count())->toBe(1);
    $this->withToken($token)->getJson('/api/v1/stock')->assertOk()
        ->assertJsonPath('data.0.stock_kg', '20.000')
        ->assertJsonPath('data.0.stock_value', 410000)
        ->assertJsonPath('totals.display_kg', '20.000');
});

it('PF-03 permits staged lots across dates and reports sorted and unsorted totals', function () {
    [$owner, $token] = v2User();
    $supplier = Supplier::query()->create(['name' => 'Supplier v2']);
    $product = v2Product();
    $purchase = v2CreatePurchase($supplier->id);
    foreach (['2026-10-22', '2026-10-23', '2026-10-24', '2026-10-25'] as $date) {
        $this->withToken($token)->postJson("/api/v1/purchases/{$purchase->id}/lots", v2LotPayload($product->id, '10.000', $date))->assertCreated();
    }
    $detail = $this->withToken($token)->getJson("/api/v1/purchases/{$purchase->id}")->assertOk();
    expect($detail->json('data.lots'))->toHaveCount(4)
        ->and($detail->json('data.sorted_kg'))->toBe('40.000')
        ->and($detail->json('data.unsorted_kg'))->toBe('60.000');
});

it('PF-04 rejects mismatched kind, excess weight and zero weight while allowing exact gross weight', function () {
    [$owner, $token] = v2User();
    $supplier = Supplier::query()->create(['name' => 'Supplier v2']);
    $product = v2Product();
    $purchase = v2CreatePurchase($supplier->id, '20.000');
    $wrong = Product::query()->create(['name' => 'Putih', 'jenis' => 'putih', 'price_retail' => 1, 'price_5kg' => 1]);
    $this->withToken($token)->postJson("/api/v1/purchases/{$purchase->id}/lots", v2LotPayload($wrong->id))->assertUnprocessable();
    $this->withToken($token)->postJson("/api/v1/purchases/{$purchase->id}/lots", v2LotPayload($product->id, '20.001'))->assertUnprocessable();
    $this->withToken($token)->postJson("/api/v1/purchases/{$purchase->id}/lots", v2LotPayload($product->id, '0.000'))->assertUnprocessable();
    $this->withToken($token)->postJson("/api/v1/purchases/{$purchase->id}/lots", v2LotPayload($product->id, '20.000'))->assertCreated();
});

it('PF-05 permits purchase changes before sorting and restricts or rejects changes after sorting', function () {
    [$owner, $token] = v2User();
    $supplier = Supplier::query()->create(['name' => 'Supplier v2']);
    $product = v2Product();
    $purchase = v2CreatePurchase($supplier->id);
    $this->withToken($token)->putJson("/api/v1/purchases/{$purchase->id}", [...v2PurchasePayload($supplier->id), 'gross_weight_kg' => '90.000'])->assertOk();
    $this->withToken($token)->deleteJson("/api/v1/purchases/{$purchase->id}")->assertOk();
    $purchase = v2CreatePurchase($supplier->id);
    $this->withToken($token)->postJson("/api/v1/purchases/{$purchase->id}/lots", v2LotPayload($product->id))->assertCreated();
    $this->withToken($token)->putJson("/api/v1/purchases/{$purchase->id}", [...v2PurchasePayload($supplier->id), 'price_per_kg' => 22000])->assertStatus(409);
    $this->withToken($token)->putJson("/api/v1/purchases/{$purchase->id}", ['notes' => 'catatan baru'])->assertOk();
    $this->withToken($token)->deleteJson("/api/v1/purchases/{$purchase->id}")->assertStatus(409);
});

it('PF-06 blocks deletion after sale and reverses a safe lot exactly', function () {
    [$owner, $token] = v2User();
    $supplier = Supplier::query()->create(['name' => 'Supplier v2']);
    $product = v2Product();
    $purchase = v2CreatePurchase($supplier->id);
    $created = $this->withToken($token)->postJson("/api/v1/purchases/{$purchase->id}/lots", v2LotPayload($product->id))->assertCreated();
    $lotId = $created->json('data.id');
    $this->withToken($token)->postJson('/api/v1/transactions', [
        'client_uuid' => '30000000-0000-4000-8000-000000000001', 'customer_category' => 'ecer',
        'items' => [['product_id' => $product->id, 'weight_kg' => '1.000']], 'payment_method' => 'cash',
        'final_total' => 27500, 'amount_paid' => 27500,
    ])->assertCreated();
    $this->withToken($token)->deleteJson("/api/v1/purchases/{$purchase->id}/lots/{$lotId}")->assertStatus(409);
    expect(StockMovement::query()->where('type', 'sale')->count())->toBe(1);
    $safeProduct = v2Product('Merah aman');
    $safe = $this->withToken($token)->postJson("/api/v1/purchases/{$purchase->id}/lots", v2LotPayload($safeProduct->id, '2.000'))->assertCreated();
    $this->withToken($token)->deleteJson("/api/v1/purchases/{$purchase->id}/lots/{$safe->json('data.id')}")->assertNoContent();
    $moves = StockMovement::query()->where('product_id', $safeProduct->id)->orderBy('id')->get();
    expect($moves)->toHaveCount(2)
        ->and($moves[0]->weight_kg)->toBe('2.000')->and((int) $moves[0]->value_delta)->toBe(41000)
        ->and($moves[1]->weight_kg)->toBe('-2.000')->and((int) $moves[1]->value_delta)->toBe(-41000);
});

it('PF-07 closes only sorted purchases, prevents new lots, reopens and audits transitions', function () {
    [$owner, $token] = v2User();
    $supplier = Supplier::query()->create(['name' => 'Supplier v2']);
    $product = v2Product();
    $empty = v2CreatePurchase($supplier->id);
    $filled = v2CreatePurchase($supplier->id);
    $this->withToken($token)->postJson("/api/v1/purchases/{$empty->id}/close")->assertStatus(409);
    $lot = $this->withToken($token)->postJson("/api/v1/purchases/{$filled->id}/lots", v2LotPayload($product->id))->assertCreated();
    $this->withToken($token)->postJson("/api/v1/purchases/{$filled->id}/close")->assertOk();
    $this->withToken($token)->postJson("/api/v1/purchases/{$filled->id}/lots", v2LotPayload($product->id))->assertStatus(409);
    $this->withToken($token)->postJson("/api/v1/purchases/{$filled->id}/reopen")->assertOk();
    expect(AuditLog::query()->whereIn('action', ['purchase.closed', 'purchase.reopened'])->count())->toBe(2);
});

it('PF-08 removes purchase finalization route', function () {
    [$owner, $token] = v2User();
    $supplier = Supplier::query()->create(['name' => 'Supplier v2']);
    $purchase = v2CreatePurchase($supplier->id);
    $this->withToken($token)->postJson("/api/v1/purchases/{$purchase->id}/finalize")->assertNotFound();
});

it('PF-09 purchase and lot endpoint state matrix never returns server errors', function () {
    [$owner, $token] = v2User();
    $supplier = Supplier::query()->create(['name' => 'Supplier v2']);
    $product = v2Product();
    $p = v2CreatePurchase($supplier->id);
    $missing = 999999;
    $states = [$p, v2CreatePurchase($supplier->id)];
    $lot = $this->withToken($token)->postJson("/api/v1/purchases/{$states[1]->id}/lots", v2LotPayload($product->id))->assertCreated();
    $this->withToken($token)->postJson("/api/v1/purchases/{$states[1]->id}/close")->assertOk();
    $calls = [
        fn () => $this->withToken($token)->getJson('/api/v1/purchases'),
        fn () => $this->withToken($token)->getJson("/api/v1/purchases/{$missing}"),
        fn () => $this->withToken($token)->putJson("/api/v1/purchases/{$missing}", ['notes' => 'x']),
        fn () => $this->withToken($token)->deleteJson("/api/v1/purchases/{$missing}"),
        fn () => $this->withToken($token)->postJson("/api/v1/purchases/{$missing}/lots", v2LotPayload($product->id)),
        fn () => $this->withToken($token)->deleteJson("/api/v1/purchases/{$states[0]->id}/lots/{$lot->json('data.id')}"),
        fn () => $this->withToken($token)->postJson("/api/v1/purchases/{$states[1]->id}/close"),
        fn () => $this->withToken($token)->postJson("/api/v1/purchases/{$states[1]->id}/reopen"),
        fn () => $this->withToken($token)->putJson("/api/v1/purchases/{$states[1]->id}", ['notes' => 'x']),
        fn () => $this->withToken($token)->deleteJson("/api/v1/purchases/{$states[1]->id}"),
        fn () => $this->withToken($token)->deleteJson("/api/v1/purchases/{$states[1]->id}/lots/{$lot->json('data.id')}"),
    ];
    foreach ($calls as $call) {
        expect($call()->status())->toBeLessThan(500);
    }
});

it('PF-10 lists exact purchase summary and detail shapes with lots always an array', function () {
    [$owner, $token] = v2User();
    $supplier = Supplier::query()->create(['name' => 'Supplier v2']);
    $product = v2Product();
    for ($i = 0; $i < 5; $i++) {
        $purchase = v2CreatePurchase($supplier->id);
        $this->withToken($token)->postJson("/api/v1/purchases/{$purchase->id}/lots", v2LotPayload($product->id, '1.000'))->assertCreated();
    }
    DB::enableQueryLog();
    DB::flushQueryLog();
    $list = $this->withToken($token)->getJson('/api/v1/purchases?include=lots&jenis=merah&sorting=sorting')->assertOk();
    $purchaseQueries = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains(strtolower($query['query']), 'stock_lots'));
    expect($list->json('summary'))->toHaveKeys(['count', 'unsorted_count', 'sorting_count', 'closed_count', 'gross_weight_kg', 'warehouse_kg'])
        ->and($list->json('data.0.lots'))->toBeArray()
        ->and($list->json('data.0'))->toHaveKeys(['code', 'sorting_status', 'sorted_kg', 'unsorted_kg', 'sold_kg', 'susut_kg', 'susut_final', 'can_edit', 'can_delete', 'can_add_lot'])
        ->and($list->json('data'))->toHaveCount(5)
        ->and($purchaseQueries->count())->toBeLessThan(10);
});

it('AV-01 calculates weighted average inventory value and cogs from the ledger', function () {
    [$owner, $token] = v2User();
    $product = v2Product();
    $supplier = Supplier::query()->create(['name' => 'Supplier AV']);
    StockMovement::query()->create(['product_id' => $product->id, 'type' => 'in_sort', 'weight_kg' => '10.000', 'value_delta' => 200000, 'occurred_at' => now()]);
    $purchase = v2CreatePurchase($supplier->id, '30.000');
    $purchase->update(['price_per_kg' => 20500, 'total_cost' => 615000]);
    $this->withToken($token)->postJson("/api/v1/purchases/{$purchase->id}/lots", v2LotPayload($product->id, '30.000'))->assertCreated();
    $sale = $this->withToken($token)->postJson('/api/v1/transactions', [
        'client_uuid' => '30000000-0000-4000-8000-000000000002', 'customer_category' => 'ecer',
        'items' => [['product_id' => $product->id, 'weight_kg' => '5.000']], 'payment_method' => 'cash', 'final_total' => 120000, 'amount_paid' => 120000,
    ])->assertCreated();
    expect($sale->json('data.items.0.cogs'))->toBe(101875)
        ->and(app(AverageCostService::class)->stock($product)['stock_kg'])->toBe('35.000')
        ->and(app(AverageCostService::class)->stock($product)['stock_value'])->toBe(713125)
        ->and(app(AverageCostService::class)->stock($product)['avg_cost'])->toBe(20375);
});

it('AV-02 depleting all positive stock uses the entire remaining stock value', function () {
    [$owner,$token] = v2User();
    $product = v2Product();
    StockMovement::query()->create(['product_id' => $product->id, 'type' => 'in_sort', 'weight_kg' => '10.000', 'value_delta' => 200001, 'occurred_at' => now()]);
    $sale = $this->withToken($token)->postJson('/api/v1/transactions', [
        'client_uuid' => '30000000-0000-4000-8000-000000000003', 'customer_category' => 'grosir',
        'items' => [['product_id' => $product->id, 'weight_kg' => '10.000']], 'payment_method' => 'cash', 'final_total' => 240000, 'amount_paid' => 240000,
    ])->assertCreated();
    expect($sale->json('data.items.0.cogs'))->toBe(200001)
        ->and(app(AverageCostService::class)->stock($product)['stock_value'])->toBe(0);
});

it('AV-06 rounds average cogs half up once per transaction item', function () {
    [$owner,$token] = v2User();
    $product = v2Product();
    StockMovement::query()->create(['product_id' => $product->id, 'type' => 'in_sort', 'weight_kg' => '3.000', 'value_delta' => 61000, 'occurred_at' => now()]);
    $sale = $this->withToken($token)->postJson('/api/v1/transactions', [
        'client_uuid' => '30000000-0000-4000-8000-000000000006', 'customer_category' => 'ecer',
        'items' => [['product_id' => $product->id, 'weight_kg' => '1.000']], 'payment_method' => 'cash', 'final_total' => 27500, 'amount_paid' => 27500,
    ])->assertCreated();
    expect($sale->json('data.items.0.cogs'))->toBe(20333)
        ->and(app(AverageCostService::class)->stock($product)['stock_value'])->toBe(40667);
});
