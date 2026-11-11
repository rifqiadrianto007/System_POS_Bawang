<?php

use App\Models\AuditLog;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\TransactionItem;
use App\Models\User;
use App\Services\AverageCostService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function z03User(string $role, string $tag): array
{
    $user = User::factory()->create(['role' => $role, 'username' => $role.'-z03-'.$tag]);

    return [$user, $user->createToken('z03-'.$tag)->plainTextToken];
}

function z03Product(string $name = 'Merah Z03', ?int $lastCost = null): Product
{
    return Product::query()->create([
        'name' => $name, 'jenis' => 'merah', 'price_retail' => 27500, 'price_5kg' => 24000,
        'last_unit_cost' => $lastCost,
    ]);
}

function z03Movement(Product $product, string $kg, int $value, string $type = 'in_sort'): StockMovement
{
    return StockMovement::query()->create([
        'product_id' => $product->id, 'type' => $type, 'weight_kg' => $kg,
        'value_delta' => $value, 'occurred_at' => now(),
    ]);
}

function z03SalePayload(Product $product, string $kg, int $amount, string $uuid): array
{
    return [
        'client_uuid' => $uuid, 'customer_category' => 'ecer',
        'items' => [['product_id' => $product->id, 'weight_kg' => $kg]],
        'payment_method' => 'cash', 'final_total' => $amount, 'amount_paid' => $amount,
    ];
}

function z03CountPayload(Product $product, string $kg, Purchase $purchase): array
{
    return ['product_id' => $product->id, 'purchase_id' => $purchase->id, 'counted_weight_kg' => $kg, 'counted_at' => now()->toIso8601String()];
}

function z03BatchStock(User $owner, Product $product, string $kg = '20.000', int $value = 410000): Purchase
{
    $purchase = z03Purchase($owner);
    $lot = $purchase->stockLots()->create(['product_id' => $product->id, 'weight_kg' => $kg,
        'sorted_at' => $purchase->purchased_at, 'unit_cost' => 20500, 'created_by' => $owner->id]);
    StockMovement::query()->create(['product_id' => $product->id, 'lot_id' => $lot->id, 'type' => 'in_sort',
        'weight_kg' => $kg, 'value_delta' => $value, 'occurred_at' => now()]);

    return $purchase;
}

function z03Balance(Product $product): array
{
    return app(AverageCostService::class)->stock($product->fresh());
}

function z03AssertLedger(Product $product): void
{
    $rows = StockMovement::query()->where('product_id', $product->id)->get();
    $kgMilli = $rows->sum(fn (StockMovement $row): int => app(AverageCostService::class)->toMilli((string) $row->weight_kg));
    $value = (int) $rows->sum('value_delta');
    $balance = z03Balance($product);
    expect($balance['stock_kg'])->toBe(app(AverageCostService::class)->formatWeight($kgMilli))
        ->and($balance['stock_value'])->toBe($value);
    $incoming = (int) $rows->where('value_delta', '>', 0)->sum('value_delta');
    $outgoing = abs((int) $rows->where('value_delta', '<', 0)->sum('value_delta'));
    expect($incoming - $outgoing)->toBe($value);
}

function z03Purchase(User $owner, string $date = '2026-11-05', string $gross = '100.000', int $price = 20000): Purchase
{
    $supplier = Supplier::query()->create(['name' => 'Supplier z03 '.$date.$price]);
    $sequence = Purchase::withTrashed()->count() + 1;

    return Purchase::query()->create([
        'code' => 'KLK-'.str_replace('-', '', substr($date, 2)).'-'.str_pad((string) $sequence, 3, '0', STR_PAD_LEFT),
        'supplier_id' => $supplier->id, 'purchased_at' => $date, 'jenis' => 'merah',
        'gross_weight_kg' => $gross, 'price_per_kg' => $price,
        'total_cost' => app(AverageCostService::class)->totalCost($gross, $price), 'created_by' => $owner->id,
    ]);
}

function z03Lot(Purchase $purchase, Product $product, User $owner, string $kg): StockLot
{
    $lot = $purchase->stockLots()->create([
        'product_id' => $product->id, 'weight_kg' => $kg, 'sorted_at' => $purchase->purchased_at,
        'unit_cost' => $purchase->price_per_kg, 'created_by' => $owner->id,
    ]);
    StockMovement::query()->create([
        'product_id' => $product->id, 'lot_id' => $lot->id, 'type' => 'in_sort', 'weight_kg' => $kg,
        'value_delta' => app(AverageCostService::class)->valueForWeight(
            app(AverageCostService::class)->toMilli($kg), (int) $purchase->price_per_kg
        ), 'occurred_at' => now(),
    ]);

    return $lot;
}

function z03LotMovement(StockLot $lot, Product $product, string $kg, int $value, string $type): StockMovement
{
    return StockMovement::query()->create(['product_id' => $product->id, 'lot_id' => $lot->id, 'type' => $type,
        'weight_kg' => $kg, 'value_delta' => $value, 'occurred_at' => now()]);
}

it('AV-03 records sale shortfall and applies fallback cost without blocking the sale', function () {
    [, $token] = z03User('kasir', 'av03');
    $product = z03Product('Merah AV03', 20000);
    z03Movement($product, '2.000', 40000);
    $response = $this->withToken($token)->postJson('/api/v1/transactions', z03SalePayload($product, '5.000', 120000, '30000000-0000-4000-8000-000000000103'))->assertCreated();
    $item = TransactionItem::query()->findOrFail($response->json('data.items.0.id'));
    expect((int) $item->cogs)->toBe(100000)
        ->and($item->shortfall_kg)->toBe('3.000')
        ->and(z03Balance($product)['stock_kg'])->toBe('-3.000')
        ->and(z03Balance($product)['stock_value'])->toBe(-60000);
});

it('AV-04 uses last unit cost then weighted same-kind purchase price then zero as shortfall fallback', function () {
    [, $token] = z03User('kasir', 'av04');
    $lastCost = z03Product('Merah last cost', 23000);
    $last = $this->withToken($token)->postJson('/api/v1/transactions', z03SalePayload($lastCost, '2.000', 55000, '30000000-0000-4000-8000-000000000104'))->assertCreated();
    expect((int) TransactionItem::query()->findOrFail($last->json('data.items.0.id'))->cogs)->toBe(46000);

    $weighted = z03Product('Merah purchase average');
    $owner = User::factory()->create(['role' => 'owner', 'username' => 'owner-z03-av04-purchase']);
    z03Purchase($owner, '2026-11-01', '10.000', 20000);
    z03Purchase($owner, '2026-11-02', '30.000', 21000);
    $sale = $this->withToken($token)->postJson('/api/v1/transactions', z03SalePayload($weighted, '2.000', 55000, '30000000-0000-4000-8000-000000000204'))->assertCreated();
    $weightedItem = TransactionItem::query()->findOrFail($sale->json('data.items.0.id'));
    expect((int) $weightedItem->cogs)->toBe(41500)
        ->and($weightedItem->shortfall_kg)->toBe('2.000');

    $zero = z03Product('Merah no reserve');
    $zeroSale = $this->withToken($token)->postJson('/api/v1/transactions', z03SalePayload($zero, '2.000', 55000, '30000000-0000-4000-8000-000000000304'))->assertCreated();
    expect((int) TransactionItem::query()->findOrFail($zeroSale->json('data.items.0.id'))->cogs)->toBe(41500);
});

it('AV-05 returns the exact item kilograms and cogs value on void', function () {
    [$owner, $token] = z03User('owner', 'av05');
    $product = z03Product('Merah AV05');
    z03Movement($product, '10.000', 200000);
    $sale = $this->withToken($token)->postJson('/api/v1/transactions', z03SalePayload($product, '2.000', 55000, '30000000-0000-4000-8000-000000000105'))->assertCreated();
    $item = $sale->json('data.items.0');
    $this->withToken($token)->postJson('/api/v1/transactions/'.$sale->json('data.id').'/void', ['reason' => 'Salah transaksi'])->assertOk();
    $return = StockMovement::query()->where('type', 'void_return')->firstOrFail();
    expect($return->weight_kg)->toBe($item['weight_kg'])
        ->and((int) $return->value_delta)->toBe($item['cogs'])
        ->and(z03Balance($product)['stock_kg'])->toBe('10.000')
        ->and(z03Balance($product)['stock_value'])->toBe(200000);
});

it('AV-07 preserves the seeded ledger identity after purchase, sale, void, batch count and cancellation', function () {
    [$owner, $token] = z03User('owner', 'av07');
    $cashier = User::factory()->create(['role' => 'kasir', 'username' => 'kasir-z03-av07']);
    $cashToken = $cashier->createToken('av07-cashier')->plainTextToken;
    $product = z03Product('Merah AV07');
    $purchase = z03Purchase($owner, '2026-11-05', '25.000', 20000);
    $lot = z03Lot($purchase, $product, $owner, '10.000');
    z03AssertLedger($product);
    $sale = $this->withToken($cashToken)->postJson('/api/v1/transactions', z03SalePayload($product, '2.000', 55000, '30000000-0000-4000-8000-000000000107'))->assertCreated();
    z03AssertLedger($product);
    $void = $this->actingAs($owner, 'sanctum')->postJson('/api/v1/transactions/'.$sale->json('data.id').'/void', ['reason' => 'Tes invariant']);
    expect($void->status())->toBe(200, $void->getContent());
    z03AssertLedger($product);
    $count = $this->withToken($token)->postJson('/api/v1/stock-counts', z03CountPayload($product, '9.000', $purchase))->assertCreated();
    z03AssertLedger($product);
    $this->withToken($token)->deleteJson('/api/v1/stock-counts/'.$count->json('data.id'), ['reason' => 'Tes batal'])->assertOk();
    z03AssertLedger($product);
});

it('AV-08 recursively hides all owner cost and shortfall fields in every cashier response', function () {
    [, $ownerToken] = z03User('owner', 'av08');
    [$cashier, $cashToken] = z03User('kasir', 'av08');
    $product = z03Product('Merah AV08', 20000);
    $create = $this->withToken($cashToken)->postJson('/api/v1/transactions', z03SalePayload($product, '2.000', 55000, '30000000-0000-4000-8000-000000000108'))->assertCreated();
    $id = $create->json('data.id');
    $responses = [
        $create->json(),
        $this->withToken($cashToken)->postJson('/api/v1/transactions', z03SalePayload($product, '2.000', 55000, '30000000-0000-4000-8000-000000000108'))->assertOk()->json(),
        $this->withToken($cashToken)->getJson('/api/v1/transactions/'.$id)->assertOk()->json(),
        $this->withToken($cashToken)->getJson('/api/v1/transactions/'.$id.'/receipt')->assertOk()->json(),
        $this->withToken($cashToken)->getJson('/api/v1/transactions')->assertOk()->json(),
        $this->withToken($cashToken)->getJson('/api/v1/products')->assertOk()->json(),
    ];
    $forbidden = ['has_shortfall', 'shortfall_kg', 'shortfall_resolved', 'avg_cost', 'stock_value', 'last_unit_cost', 'cogs'];
    $walk = function (mixed $value) use (&$walk, $forbidden): void {
        if (! is_array($value)) {
            return;
        }
        foreach ($value as $key => $child) {
            expect($forbidden)->not->toContain((string) $key);
            $walk($child);
        }
    };
    foreach ($responses as $response) {
        $walk($response);
    }
});

it('CT-01 applies weighted average display shrink with exact loss value', function () {
    [$owner,$token] = z03User('owner', 'ct01');
    $product = z03Product('Merah CT01');
    $purchase = z03BatchStock($owner, $product);
    $response = $this->withToken($token)->postJson('/api/v1/stock-counts', z03CountPayload($product, '18.500', $purchase))->assertCreated();
    expect($response->json('data.loss_value'))->toBe(30750)->and(z03Balance($product)['stock_kg'])->toBe('18.500')->and(z03Balance($product)['stock_value'])->toBe(379250);
});

it('CT-02 values a positive correction at average unit cost and records negative loss', function () {
    [$owner,$token] = z03User('owner', 'ct02');
    $product = z03Product('Merah CT02');
    $purchase = z03BatchStock($owner, $product);
    $response = $this->withToken($token)->postJson('/api/v1/stock-counts', z03CountPayload($product, '22.000', $purchase))->assertCreated();
    expect($response->json('data.loss_value'))->toBe(-41000)->and(z03Balance($product)['stock_value'])->toBe(451000);
});

it('CT-03 resets zero or negative system stock to counted stock without profit or loss', function () {
    [$owner,$token] = z03User('owner', 'ct03');
    $product = z03Product('Merah CT03', 20000);
    $purchase = z03Purchase($owner);
    $zero = $this->withToken($token)->postJson('/api/v1/stock-counts', z03CountPayload($product, '5.000', $purchase))->assertCreated();
    expect($zero->json('data.loss_value'))->toBe(0)->and(z03Balance($product)['stock_value'])->toBe(100000);
    $lot = StockLot::query()->where('purchase_id', $purchase->id)->where('product_id', $product->id)->firstOrFail();
    z03LotMovement($lot, $product, '-8.000', -160000, 'sale');
    $negative = $this->withToken($token)->postJson('/api/v1/stock-counts', z03CountPayload($product, '2.000', $purchase))->assertCreated();
    expect($negative->json('data.loss_value'))->toBe(0)->and(z03Balance($product)['stock_kg'])->toBe('2.000')->and(z03Balance($product)['stock_value'])->toBe(40000);
    $this->withToken($token)->postJson('/api/v1/stock-counts', z03CountPayload($product, '-0.001', $purchase))->assertUnprocessable();
});

it('CT-04 only cancels latest active count, requires a reason and writes exact reversal audit', function () {
    [$owner,$token] = z03User('owner', 'ct04');
    $product = z03Product('Merah CT04');
    $purchase = z03BatchStock($owner, $product);
    $first = $this->withToken($token)->postJson('/api/v1/stock-counts', z03CountPayload($product, '19.000', $purchase))->assertCreated();
    $second = $this->withToken($token)->postJson('/api/v1/stock-counts', z03CountPayload($product, '18.000', $purchase))->assertCreated();
    $this->withToken($token)->deleteJson('/api/v1/stock-counts/'.$first->json('data.id'), ['reason' => 'Bukan terbaru'])->assertStatus(409);
    $this->withToken($token)->deleteJson('/api/v1/stock-counts/'.$second->json('data.id'))->assertUnprocessable();
    $this->withToken($token)->deleteJson('/api/v1/stock-counts/'.$second->json('data.id'), ['reason' => 'Batal hitung'])->assertOk();
    $this->withToken($token)->deleteJson('/api/v1/stock-counts/'.$second->json('data.id'), ['reason' => 'Batal lagi'])->assertStatus(409);
    $reversal = StockMovement::query()->where('ref_type', 'stock_count_cancelled')->latest('id')->firstOrFail();
    expect($reversal->type)->toBe('count_reversal')->and($reversal->weight_kg)->toBe('1.000')->and((int) $reversal->value_delta)->toBe(20500)
        ->and(AuditLog::query()->where('action', 'stock_count.cancelled')->count())->toBe(1);
});

it('CT-05 cancellation restores ledger but does not clear a sale shortfall', function () {
    [$owner,$ownerToken] = z03User('owner', 'ct05');
    [, $cashToken] = z03User('kasir', 'ct05');
    $product = z03Product('Merah CT05', 20500);
    $purchase = z03BatchStock($owner, $product);
    $count = $this->withToken($ownerToken)->postJson('/api/v1/stock-counts', z03CountPayload($product, '0.000', $purchase))->assertCreated();
    $sale = $this->withToken($cashToken)->postJson('/api/v1/transactions', z03SalePayload($product, '2.000', 55000, '30000000-0000-4000-8000-000000000305'))->assertCreated();
    expect($sale->json('data.items.0.shortfall_kg'))->toBe('2.000');
    $this->withToken($ownerToken)->deleteJson('/api/v1/stock-counts/'.$count->json('data.id'), ['reason' => 'Salah hitung'])->assertOk();
    $detail = $this->withToken($ownerToken)->getJson('/api/v1/transactions/'.$sale->json('data.id'))->assertOk();
    expect($detail->json('data.has_shortfall'))->toBeTrue()->and(z03Balance($product)['stock_kg'])->toBe('18.000');
});
