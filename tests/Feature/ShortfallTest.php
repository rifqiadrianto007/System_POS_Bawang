<?php

use App\Models\Product;
use App\Models\Purchase;
use App\Models\SaleAllocation;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function shortfallUser(string $role, string $suffix): array
{
    $user = User::factory()->create([
        'role' => $role,
        'username' => $role.'-shortfall-'.$suffix,
    ]);

    return [$user, $user->createToken('shortfall-test')->plainTextToken];
}

function shortfallProduct(string $name = 'Merah SF'): Product
{
    return Product::query()->create([
        'name' => $name,
        'price_retail' => 27500,
        'price_3kg' => 26000,
        'price_5kg' => 24000,
    ]);
}

function shortfallPurchase(User $owner, string $status = 'finalized', string $date = '2026-10-20'): Purchase
{
    $supplier = Supplier::query()->create(['name' => 'Supplier SF '.$date]);

    return Purchase::query()->create([
        'supplier_id' => $supplier->id,
        'purchased_at' => $date,
        'gross_weight_kg' => '10.000',
        'price_per_kg' => 21250,
        'total_cost' => 106250,
        'status' => $status,
        'cost_per_clean_kg' => $status === 'finalized' ? 21250 : null,
        'clean_weight_kg' => $status === 'finalized' ? '10.000' : null,
        'finalized_at' => $status === 'finalized' ? $date.' 01:00:00' : null,
        'created_by' => $owner->id,
    ]);
}

function shortfallLot(Purchase $purchase, Product $product, string $weight, int $cost = 21250): StockLot
{
    $lot = $purchase->stockLots()->create([
        'product_id' => $product->id,
        'clean_weight_kg' => $weight,
        'unit_cost' => $purchase->status === 'finalized' ? $cost : null,
        'cleaned_at' => (string) $purchase->getRawOriginal('purchased_at'),
    ]);

    if ($purchase->status === 'finalized') {
        StockMovement::query()->create([
            'product_id' => $product->id,
            'lot_id' => $lot->id,
            'type' => 'in_clean',
            'weight_kg' => $weight,
            'unit_cost' => $cost,
            'occurred_at' => substr((string) $purchase->getRawOriginal('purchased_at'), 0, 10).' 01:00:00',
        ]);
    }

    return $lot;
}

function shortfallPayload(int $productId, string $weight, string $uuid): array
{
    $price = (bccomp($weight, '5.000', 3) >= 0) ? '24000' : '27500';
    $total = (int) bcmul($weight, $price, 0);

    return [
        'client_uuid' => $uuid,
        'customer_category' => 'ecer',
        'items' => [['product_id' => $productId, 'weight_kg' => $weight]],
        'payment_method' => 'cash',
        'final_total' => $total,
        'amount_paid' => $total,
        'occurred_at' => '2026-10-22T10:00:00+07:00',
    ];
}

function shortfallInvariants(Product $product): void
{
    $stock = (int) round((float) StockMovement::query()
        ->where('product_id', $product->id)->sum('weight_kg') * 1000);
    $lots = (int) round((float) StockMovement::query()
        ->where('product_id', $product->id)
        ->whereNotNull('lot_id')
        ->whereHas('lot.purchase', fn ($query) => $query->where('status', 'finalized'))
        ->sum('weight_kg') * 1000);
    $shortfall = (int) round((float) SaleAllocation::query()
        ->join('transaction_items', 'transaction_items.id', '=', 'sale_allocations.transaction_item_id')
        ->join('transactions', 'transactions.id', '=', 'transaction_items.transaction_id')
        ->where('transaction_items.product_id', $product->id)
        ->where('transactions.status', 'completed')
        ->where('sale_allocations.is_shortfall', true)
        ->sum('sale_allocations.weight_kg') * 1000);

    expect($stock)->toBe($lots - $shortfall)
        ->and($shortfall)->toBeGreaterThanOrEqual(0)
        ->and(StockMovement::query()->where('product_id', $product->id)
            ->whereNotNull('lot_id')->whereHas('lot.purchase', fn ($query) => $query->where('status', 'finalized'))
            ->selectRaw('lot_id, SUM(weight_kg) total')->groupBy('lot_id')->pluck('total')
            ->every(fn (mixed $weight): bool => (float) $weight >= 0))->toBeTrue();
}

it('SF-01 records a null-lot shortfall and exposes it to the owner', function () {
    [$owner, $token] = shortfallUser('owner', 'sf01');
    $product = shortfallProduct();

    $response = $this->withToken($token)->postJson('/api/v1/transactions', shortfallPayload(
        $product->id,
        '2.000',
        '00000000-0000-4000-8000-000000000001'
    ));

    $response->assertCreated()
        ->assertJsonPath('data.items.0.cogs', 0)
        ->assertJsonPath('data.has_shortfall', true);
    $allocation = SaleAllocation::query()->firstOrFail();
    expect($allocation->lot_id)->toBeNull()->and($allocation->unit_cost)->toBe(0)->and($allocation->is_shortfall)->toBeTrue();
    expect(StockMovement::query()->where('type', 'sale')->whereNull('lot_id')->value('weight_kg'))->toBe('-2.000');
    shortfallInvariants($product);
});

it('SF-02 never allocates or displays an open purchase lot', function () {
    [$owner, $token] = shortfallUser('owner', 'sf02');
    $product = shortfallProduct();
    $purchase = shortfallPurchase($owner, 'open');
    $lot = shortfallLot($purchase, $product, '5.000');

    $this->withToken($token)->postJson('/api/v1/transactions', shortfallPayload(
        $product->id,
        '1.000',
        '00000000-0000-4000-8000-000000000002'
    ))->assertCreated();

    expect(SaleAllocation::query()->firstOrFail()->lot_id)->toBeNull()
        ->and(StockMovement::query()->where('lot_id', $lot->id)->count())->toBe(0);
    $stock = $this->withToken($token)->getJson('/api/v1/stock')->assertOk()->json('data');
    expect(collect($stock)->flatMap(fn (array $row): array => $row['lots'])->pluck('lot_id')->contains($lot->id))->toBeFalse();
    shortfallInvariants($product);
});

it('SF-03 splits available finalized stock and shortfall', function () {
    [$owner, $token] = shortfallUser('owner', 'sf03');
    $product = shortfallProduct();
    $lot = shortfallLot(shortfallPurchase($owner), $product, '3.000');

    $response = $this->withToken($token)->postJson('/api/v1/transactions', shortfallPayload(
        $product->id,
        '5.000',
        '00000000-0000-4000-8000-000000000003'
    ))->assertCreated();
    $allocations = SaleAllocation::query()->orderBy('id')->get();

    expect($allocations)->toHaveCount(2)
        ->and($allocations[0]->lot_id)->toBe($lot->id)
        ->and($allocations[0]->weight_kg)->toBe('3.000')
        ->and($allocations[1]->lot_id)->toBeNull()
        ->and($allocations[1]->weight_kg)->toBe('2.000')
        ->and($response->json('data.items.0.cogs'))->toBe(63750);
    shortfallInvariants($product);
});

it('SF-04 backfills the oldest shortfall during finalization', function () {
    [$owner, $token] = shortfallUser('owner', 'sf04');
    $product = shortfallProduct();
    $this->withToken($token)->postJson('/api/v1/transactions', shortfallPayload(
        $product->id,
        '2.000',
        '00000000-0000-4000-8000-000000000004'
    ))->assertCreated();
    $purchase = shortfallPurchase($owner, 'open', '2026-10-23');
    $lot = shortfallLot($purchase, $product, '5.000');

    $this->withToken($token)->postJson("/api/v1/purchases/{$purchase->id}/finalize")
        ->assertOk()
        ->assertJsonPath('data.shortfall_resolved.kg', '2.000')
        ->assertJsonPath('data.shortfall_resolved.transactions_count', 1)
        ->assertJsonPath('data.shortfall_resolved.cogs_added', 42500);

    $allocation = SaleAllocation::query()->firstOrFail();
    expect($allocation->lot_id)->toBe($lot->id)
        ->and($allocation->unit_cost)->toBe(21250)
        ->and($allocation->is_shortfall)->toBeFalse()
        ->and($allocation->backfilled_at)->not->toBeNull()
        ->and(Transaction::query()->firstOrFail()->items()->firstOrFail()->cogs)->toBe(42500);
    shortfallInvariants($product);
});

it('SF-05 leaves the unresolved remainder as shortfall', function () {
    [$owner, $token] = shortfallUser('owner', 'sf05');
    $product = shortfallProduct();
    $this->withToken($token)->postJson('/api/v1/transactions', shortfallPayload(
        $product->id,
        '2.000',
        '00000000-0000-4000-8000-000000000005'
    ))->assertCreated();
    $purchase = shortfallPurchase($owner, 'open', '2026-10-23');
    shortfallLot($purchase, $product, '1.500');

    $this->withToken($token)->postJson("/api/v1/purchases/{$purchase->id}/finalize")->assertOk();
    $resolved = (float) SaleAllocation::query()->where('is_shortfall', false)->sum('weight_kg');
    $remaining = (float) SaleAllocation::query()->where('is_shortfall', true)->sum('weight_kg');
    expect(number_format($resolved, 3, '.', ''))->toBe('1.500')
        ->and(number_format($remaining, 3, '.', ''))->toBe('0.500');
    shortfallInvariants($product);
});

it('SF-06 resolves shortfalls by transaction time and allocation id', function () {
    [$owner, $token] = shortfallUser('owner', 'sf06');
    $product = shortfallProduct();
    foreach (['2026-10-22T10:00:00+07:00', '2026-10-22T11:00:00+07:00'] as $index => $time) {
        $payload = shortfallPayload($product->id, '1.000', sprintf('00000000-0000-4000-8000-%012d', 6 + $index));
        $payload['occurred_at'] = $time;
        $this->withToken($token)->postJson('/api/v1/transactions', $payload)->assertCreated();
    }
    $purchase = shortfallPurchase($owner, 'open', '2026-10-23');
    shortfallLot($purchase, $product, '1.000');
    $this->withToken($token)->postJson("/api/v1/purchases/{$purchase->id}/finalize")->assertOk();

    $allocations = SaleAllocation::query()->orderBy('id')->get();
    expect($allocations[0]->is_shortfall)->toBeFalse()->and($allocations[1]->is_shortfall)->toBeTrue();
});

it('SF-07 includes backfilled cogs in the finance report', function () {
    [$owner, $token] = shortfallUser('owner', 'sf07');
    $product = shortfallProduct();
    $this->withToken($token)->postJson('/api/v1/transactions', shortfallPayload(
        $product->id, '2.000', '00000000-0000-4000-8000-000000000007'
    ))->assertCreated();
    $purchase = shortfallPurchase($owner, 'open', '2026-10-23');
    shortfallLot($purchase, $product, '2.000');
    $this->withToken($token)->postJson("/api/v1/purchases/{$purchase->id}/finalize")->assertOk();

    $this->withToken($token)->getJson('/api/v1/reports/finance?period=daily&date=2026-10-22')
        ->assertOk()->assertJsonPath('data.cogs', 106250)->assertJsonPath('data.shortfall_count', 0);
});

it('SF-08 voids unresolved and resolved shortfalls correctly', function () {
    [$owner, $token] = shortfallUser('owner', 'sf08');
    $product = shortfallProduct();
    $transactionId = $this->withToken($token)->postJson('/api/v1/transactions', shortfallPayload(
        $product->id, '2.000', '00000000-0000-4000-8000-000000000008'
    ))->json('data.id');
    $this->withToken($token)->postJson("/api/v1/transactions/{$transactionId}/void", ['reason' => 'Koreksi'])->assertOk();
    shortfallInvariants($product);

    $purchase = shortfallPurchase($owner, 'open', '2026-10-23');
    shortfallLot($purchase, $product, '2.000');
    $this->withToken($token)->postJson("/api/v1/purchases/{$purchase->id}/finalize")->assertOk();
    expect(StockMovement::query()->where('ref_type', 'shortfall_resolve')->count())->toBe(0);
});

it('SF-09 preserves the invariant through a seeded operation sequence', function () {
    mt_srand(9009);
    [$owner, $token] = shortfallUser('owner', 'sf09');
    $product = shortfallProduct();
    $purchase = shortfallPurchase($owner, 'open', '2026-10-20');
    shortfallLot($purchase, $product, '4.000');
    shortfallInvariants($product);
    $this->withToken($token)->postJson("/api/v1/purchases/{$purchase->id}/finalize")->assertOk();
    shortfallInvariants($product);
    $transactionResponse = $this->withToken($token)->postJson('/api/v1/transactions', shortfallPayload(
        $product->id, '5.000', '00000000-0000-4000-8000-000000000009'
    ))->assertCreated();
    $transactionId = $transactionResponse->json('data.id');
    shortfallInvariants($product);
    $this->withToken($token)->postJson("/api/v1/transactions/{$transactionId}/void", ['reason' => 'Uji'])->assertOk();
    shortfallInvariants($product);
});

it('SF-10 uses finalized lot weight rather than negative ledger weight for recount', function () {
    [$owner, $token] = shortfallUser('owner', 'sf10');
    $product = shortfallProduct();
    $purchase = shortfallPurchase($owner);
    shortfallLot($purchase, $product, '1.250');
    $this->withToken($token)->postJson('/api/v1/transactions', shortfallPayload(
        $product->id, '1.250', '00000000-0000-4000-8000-000000000010'
    ))->assertCreated();

    $this->withToken($token)->postJson('/api/v1/stock-counts', [
        'product_id' => $product->id, 'counted_weight_kg' => '0.000', 'counted_at' => '2026-10-22',
    ])->assertCreated()->assertJsonPath('data.system_weight_kg', '0.000')->assertJsonPath('data.loss_value', 0);
});

it('SF-11 hides shortfall keys from cashier responses', function () {
    [, $token] = shortfallUser('kasir', 'sf11');
    $product = shortfallProduct();
    $response = $this->withToken($token)->postJson('/api/v1/transactions', shortfallPayload(
        $product->id, '1.000', '00000000-0000-4000-8000-000000000011'
    ))->assertCreated();

    $json = json_encode($response->json(), JSON_THROW_ON_ERROR);
    expect($json)->not->toContain('has_shortfall')->not->toContain('is_shortfall')->not->toContain('shortfall_kg');
});

it('SF-12 exposes shortfall_kg as a formatted owner field', function () {
    [, $token] = shortfallUser('owner', 'sf12');
    $product = shortfallProduct();
    $this->withToken($token)->postJson('/api/v1/transactions', shortfallPayload(
        $product->id, '1.250', '00000000-0000-4000-8000-000000000012'
    ))->assertCreated();

    $this->withToken($token)->getJson('/api/v1/stock')->assertJsonPath('data.0.shortfall_kg', '1.250');
    $this->withToken($token)->getJson('/api/v1/products')->assertJsonPath('data.0.shortfall_kg', '1.250');
    $this->withToken($token)->getJson('/api/v1/reports/stock?from=2026-10-01&to=2026-10-27')
        ->assertJsonPath('data.shortfall_kg', '1.250')->assertJsonPath('data.products.0.shortfall_kg', '1.250');
});

it('SF-13 rounds cogs once across closed allocations', function () {
    [$owner, $token] = shortfallUser('owner', 'sf13');
    $product = shortfallProduct();
    shortfallLot(shortfallPurchase($owner, 'finalized', '2026-10-20'), $product, '1.000', 21251);
    shortfallLot(shortfallPurchase($owner, 'finalized', '2026-10-21'), $product, '1.000', 21252);
    $response = $this->withToken($token)->postJson('/api/v1/transactions', shortfallPayload(
        $product->id, '2.000', '00000000-0000-4000-8000-000000000013'
    ))->assertCreated();

    expect($response->json('data.items.0.cogs'))->toBe(42503);
});
