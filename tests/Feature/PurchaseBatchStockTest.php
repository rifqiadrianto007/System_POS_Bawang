<?php

use App\Models\Product;
use App\Models\AuditLog;
use App\Models\Purchase;
use App\Models\StockCount;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use App\Services\TransactionService;

uses(RefreshDatabase::class);

function batchStockOwner(): array
{
    $owner = User::factory()->create(['role' => 'owner']);

    return [$owner, $owner->createToken('batch-stock')->plainTextToken];
}

function batchStockProduct(string $name, string $jenis): Product
{
    return Product::query()->create(['name' => $name, 'jenis' => $jenis, 'price_retail' => 27000, 'price_5kg' => 23000]);
}

function batchStockPurchasePayload(int $supplierId, string $jenis = 'merah'): array
{
    return ['supplier_id' => $supplierId, 'purchased_at' => now('Asia/Jakarta')->toDateString(), 'jenis' => $jenis,
        'gross_weight_kg' => '100.000', 'price_per_kg' => 21000, 'notes' => null];
}

it('reports an unadded purchase as unsorted stock without exposing a warehouse card', function () {
    [$owner, $token] = batchStockOwner();
    $supplier = Supplier::query()->create(['name' => 'Pemasok Batch']);

    $purchase = $this->withToken($token)->postJson('/api/v1/purchases', batchStockPurchasePayload($supplier->id))
        ->assertCreated()->assertJsonPath('data.gross_weight_kg', '100.000')
        ->assertJsonPath('data.added_weight_kg', '0.000')->assertJsonPath('data.remaining_weight_kg', '100.000')
        ->assertJsonPath('data.status_batch', 'belum_selesai')->assertJsonPath('data.is_locked', false)
        ->assertJsonMissingPath('data.sold_kg')->assertJsonMissingPath('data.susut_kg')->json('data');

    $stock = $this->withToken($token)->getJson('/api/v1/stock')->assertOk()
        ->assertJsonMissingPath('totals.warehouse_kg')->assertJsonPath('totals.display_kg', '0.000')
        ->assertJsonPath('totals.unsorted_kg', '100.000')->assertJsonPath('totals.unsorted_value', 2100000)
        ->assertJsonPath('totals.inventory_value', 2100000)->json();
    expect($stock['totals'])->toHaveKeys(['sold_kg', 'shrink_kg', 'shrink_value']);

    $this->withToken($token)->getJson("/api/v1/purchases/{$purchase['id']}")
        ->assertOk()->assertJsonMissingPath('data.closed_at');
});

it('filters purchase batches by jenis and returns matching stock product identity', function () {
    [, $token] = batchStockOwner();
    $supplier = Supplier::query()->create(['name' => 'Pemasok Filter Jenis']);
    $redProduct = batchStockProduct('Merah Filter A', 'merah');
    $whiteProduct = batchStockProduct('Putih Filter A', 'putih');

    $redPurchase = $this->withToken($token)->postJson('/api/v1/purchases', batchStockPurchasePayload($supplier->id, 'merah'))
        ->assertCreated()->json('data');
    $whitePurchase = $this->withToken($token)->postJson('/api/v1/purchases', batchStockPurchasePayload($supplier->id, 'putih'))
        ->assertCreated()->assertJsonPath('data.jenis', 'putih')->json('data');

    $whiteResults = $this->withToken($token)->getJson('/api/v1/purchases?jenis=putih&per_page=50')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $whitePurchase['id'])
        ->assertJsonPath('data.0.jenis', 'putih')->json('data');
    expect($whiteResults)->toHaveCount(1)
        ->and($whiteResults[0]['code'])->toBe($whitePurchase['code'])
        ->and($redPurchase['id'])->not->toBe($whiteResults[0]['id']);

    $stockRows = $this->withToken($token)->getJson('/api/v1/stock')->assertOk()->json('data');
    expect(collect($stockRows)->firstWhere('product_id', $redProduct->id))
        ->toMatchArray(['product_id' => $redProduct->id, 'name' => 'Merah Filter A', 'jenis' => 'merah'])
        ->and(collect($stockRows)->firstWhere('product_id', $whiteProduct->id))
        ->toMatchArray(['product_id' => $whiteProduct->id, 'name' => 'Putih Filter A', 'jenis' => 'putih']);
});

it('uses addition history for batch remainder and counts stock by product without a batch', function () {
    [, $token] = batchStockOwner();
    $supplier = Supplier::query()->create(['name' => 'Pemasok Contoh']);
    $redA = batchStockProduct('Merah Grade A', 'merah');
    $redB = batchStockProduct('Merah Grade B', 'merah');
    $purchase = $this->withToken($token)->postJson('/api/v1/purchases', batchStockPurchasePayload($supplier->id))
        ->assertCreated()->json('data');

    foreach ([[$redA, '30.000'], [$redB, '25.000']] as [$product, $weight]) {
        $this->withToken($token)->postJson('/api/v1/stock-additions', [
            'batch_id' => $purchase['id'], 'product_id' => $product->id, 'weight_kg' => $weight,
        ])->assertCreated();
        $this->withToken($token)->postJson('/api/v1/stock-counts', [
            'product_id' => $product->id, 'counted_weight_kg' => $weight,
            'counted_at' => now('Asia/Jakarta')->toIso8601String(),
        ])->assertCreated()->assertJsonPath('data.purchase_id', null);
    }

    $detail = $this->withToken($token)->getJson('/api/v1/purchases/'.$purchase['id'])->assertOk()
        ->assertJsonPath('data.gross_weight_kg', '100.000')->assertJsonPath('data.added_weight_kg', '55.000')
        ->assertJsonPath('data.remaining_weight_kg', '45.000')->assertJsonPath('data.status_batch', 'belum_selesai')
        ->assertJsonPath('data.is_locked', false)->assertJsonMissingPath('data.sold_kg')
        ->assertJsonMissingPath('data.susut_kg')->assertJsonMissingPath('data.lots')->json('data');

    $this->withToken($token)->getJson('/api/v1/stock')->assertOk()
        ->assertJsonPath('totals.display_kg', '55.000')->assertJsonPath('totals.unsorted_kg', '45.000')
        ->assertJsonPath('data.0.stock_kg', '30.000')->assertJsonPath('data.1.stock_kg', '25.000');

    expect($detail)->not->toHaveKey('lots')
        ->and(StockCount::query()->count())->toBe(2)
        ->and(DB::table('stock_additions')->where('purchase_id', $purchase['id'])->count())->toBe(2)
        ->and(StockLot::query()->where('purchase_id', $purchase['id'])->count())->toBe(0);
});

it('removes old close and finalize purchase routes and allows a count without choosing a batch', function () {
    [, $token] = batchStockOwner();
    $supplier = Supplier::query()->create(['name' => 'Pemasok Contoh']);
    $white = batchStockProduct('Putih Grade A', 'putih');
    $purchase = $this->withToken($token)->postJson('/api/v1/purchases', batchStockPurchasePayload($supplier->id))
        ->assertCreated()->json('data');

    $this->withToken($token)->postJson('/api/v1/purchases/'.$purchase['id'].'/close')->assertNotFound();
    $this->withToken($token)->postJson('/api/v1/purchases/'.$purchase['id'].'/finalize')->assertNotFound();
    $this->withToken($token)->postJson('/api/v1/stock-counts', [
        'product_id' => $white->id, 'counted_weight_kg' => '5.000',
        'counted_at' => now('Asia/Jakarta')->toIso8601String(),
    ])->assertCreated()->assertJsonPath('data.purchase_id', null)->assertJsonPath('data.surplus_kg', '5.000');
});

it('keeps batch addition history fixed when product recount adjusts stock and cancellation reverses it', function () {
    [, $token] = batchStockOwner();
    $supplier = Supplier::query()->create(['name' => 'Pemasok Recount']);
    $product = batchStockProduct('Merah Recount', 'merah');
    $purchase = $this->withToken($token)->postJson('/api/v1/purchases', batchStockPurchasePayload($supplier->id))
        ->assertCreated()->json('data');
    $headers = ['Authorization' => 'Bearer '.$token];

    $this->withHeaders($headers)->postJson('/api/v1/stock-additions', [
        'batch_id' => $purchase['id'], 'product_id' => $product->id, 'weight_kg' => '30.000',
    ])->assertCreated();
    $this->withHeaders($headers)->postJson('/api/v1/stock-counts', [
        'product_id' => $product->id, 'counted_weight_kg' => '30.000',
        'counted_at' => now('Asia/Jakarta')->toIso8601String(),
    ])->assertCreated();
    $latestCount = $this->withHeaders($headers)->postJson('/api/v1/stock-counts', [
        'product_id' => $product->id, 'counted_weight_kg' => '28.000',
        'counted_at' => now('Asia/Jakarta')->addMinute()->toIso8601String(),
    ])->assertCreated()->json('data');

    $this->withHeaders($headers)->getJson('/api/v1/purchases/'.$purchase['id'])->assertOk()
        ->assertJsonPath('data.added_weight_kg', '30.000')->assertJsonPath('data.remaining_weight_kg', '70.000');

    $this->withHeaders($headers)->deleteJson('/api/v1/stock-counts/'.$latestCount['id'], ['reason' => 'Koreksi hitung ulang'])->assertOk();
    $this->withHeaders($headers)->getJson('/api/v1/purchases/'.$purchase['id'])->assertOk()
        ->assertJsonPath('data.added_weight_kg', '30.000')->assertJsonPath('data.remaining_weight_kg', '70.000');
    expect(app(\App\Services\AverageCostService::class)->stock($product)['stock_kg'])->toBe('30.000');
});

it('finishes sorting and records the exact remaining cleaning shrink and audit', function () {
    [$owner, $token] = batchStockOwner();
    $supplier = Supplier::query()->create(['name' => 'Pemasok Finish']);
    $product = batchStockProduct('Merah Finish', 'merah');
    $purchase = $this->withToken($token)->postJson('/api/v1/purchases', batchStockPurchasePayload($supplier->id))
        ->assertCreated()->json('data');
    DB::table('stock_additions')->insert([
        'purchase_id' => $purchase['id'], 'product_id' => $product->id, 'weight_kg' => '98.000',
        'added_at' => now()->toDateString(), 'created_by' => $owner->id, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $finished = $this->withToken($token)->postJson('/api/v1/purchases/'.$purchase['id'].'/finish-sorting')
        ->assertOk()->assertJsonPath('data.status_batch', 'selesai_disortir')
        ->assertJsonPath('data.is_locked', true)->assertJsonPath('data.cleaning_shrink_kg', '2.000')
        ->assertJsonPath('data.cleaning_loss_value', 42000)->json('data');

    expect($finished['sorted_finished_at'])->not->toBeNull()
        ->and(AuditLog::query()->where('action', 'purchase.sorting_finished')->firstOrFail()->payload)
        ->toMatchArray(['cleaning_shrink_kg' => '2.000', 'cleaning_loss_value' => 42000]);
    $this->withToken($token)->postJson('/api/v1/purchases/'.$purchase['id'].'/finish-sorting')
        ->assertStatus(409);
});

it('allows zero remaining shrink and blocks legacy batches until stock reconciliation', function () {
    [$owner, $token] = batchStockOwner();
    $supplier = Supplier::query()->create(['name' => 'Pemasok Finish 0']);
    $product = batchStockProduct('Merah Finish 0', 'merah');
    $full = $this->withToken($token)->postJson('/api/v1/purchases', batchStockPurchasePayload($supplier->id))
        ->assertCreated()->json('data');
    DB::table('stock_additions')->insert([
        'purchase_id' => $full['id'], 'product_id' => $product->id, 'weight_kg' => '100.000',
        'added_at' => now()->toDateString(), 'created_by' => $owner->id, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->withToken($token)->postJson('/api/v1/purchases/'.$full['id'].'/finish-sorting')
        ->assertOk()->assertJsonPath('data.cleaning_shrink_kg', '0.000')->assertJsonPath('data.cleaning_loss_value', 0);

    $legacy = $this->withToken($token)->postJson('/api/v1/purchases', batchStockPurchasePayload($supplier->id))
        ->assertCreated()->json('data');
    Purchase::query()->whereKey($legacy['id'])->update(['needs_stock_reconciliation' => true]);
    $this->withToken($token)->postJson('/api/v1/purchases/'.$legacy['id'].'/finish-sorting')
        ->assertStatus(409)->assertJsonPath('message', 'Riwayat stok batch lama perlu direkonsiliasi sebelum batch dapat diselesaikan.');
    $this->withToken($token)->postJson('/api/v1/stock-additions', [
        'batch_id' => $legacy['id'], 'product_id' => $product->id, 'weight_kg' => '95.000',
    ])->assertCreated();
    $this->withToken($token)->postJson('/api/v1/purchases/'.$legacy['id'].'/finish-sorting')
        ->assertOk()->assertJsonPath('data.needs_stock_reconciliation', false)
        ->assertJsonPath('data.cleaning_shrink_kg', '5.000');
});

it('restricts batch sorting completion to owners', function () {
    [, $token] = batchStockOwner();
    $supplier = Supplier::query()->create(['name' => 'Pemasok Kasir Finish']);
    $purchase = $this->withToken($token)->postJson('/api/v1/purchases', batchStockPurchasePayload($supplier->id))
        ->assertCreated()->json('data');
    $cashier = User::factory()->create(['role' => 'kasir']);

    $this->actingAs($cashier, 'sanctum')
        ->postJson('/api/v1/purchases/'.$purchase['id'].'/finish-sorting')->assertForbidden();
});

it('adds sorted stock from a batch to product ledger and retains the addition history', function () {
    [$owner, $token] = batchStockOwner();
    $supplier = Supplier::query()->create(['name' => 'Pemasok Add']);
    $product = batchStockProduct('Merah Add', 'merah');
    $purchase = $this->withToken($token)->postJson('/api/v1/purchases', batchStockPurchasePayload($supplier->id))
        ->assertCreated()->json('data');

    $response = $this->withToken($token)->postJson('/api/v1/stock-additions', [
        'batch_id' => $purchase['id'], 'product_id' => $product->id, 'weight_kg' => '10.000',
    ])->assertCreated()->assertJsonPath('data.batch_id', $purchase['id'])
        ->assertJsonPath('data.weight_kg', '10.000');

    expect(DB::table('stock_additions')->where('purchase_id', $purchase['id'])->sum('weight_kg'))->toBe(10)
        ->and(StockMovement::query()->where('ref_type', \App\Models\StockAddition::class)->count())->toBe(1);
    $this->withToken($token)->getJson('/api/v1/stock')->assertOk()
        ->assertJsonPath('totals.display_kg', '10.000')->assertJsonPath('totals.unsorted_kg', '90.000')
        ->assertJsonPath('totals.stock_value', 210000);
});

it('lists product batch names with the total weight ever added from each batch', function () {
    [$owner, $token] = batchStockOwner();
    $supplier = Supplier::query()->create(['name' => 'Pemasok Batch List']);
    $product = batchStockProduct('Merah Batch List', 'merah');
    $purchase = $this->withToken($token)->postJson('/api/v1/purchases', batchStockPurchasePayload($supplier->id))
        ->assertCreated()->json('data');
    foreach (['3.000', '2.500'] as $weight) {
        $this->withToken($token)->postJson('/api/v1/stock-additions', [
            'batch_id' => $purchase['id'], 'product_id' => $product->id, 'weight_kg' => $weight,
        ])->assertCreated();
    }

    $this->withToken($token)->getJson('/api/v1/products/'.$product->id.'/batches')->assertOk()
        ->assertJsonPath('data.0.batch_id', $purchase['id'])
        ->assertJsonPath('data.0.batch_name', $purchase['code'])
        ->assertJsonPath('data.0.added_weight_kg', '5.500');
    $cashier = User::factory()->create(['role' => 'kasir']);
    $this->actingAs($cashier, 'sanctum')->getJson('/api/v1/products/'.$product->id.'/batches')->assertForbidden();
});

it('prices sales from product weighted average regardless of batch arrival order', function () {
    [$owner, $token] = batchStockOwner();
    $supplier = Supplier::query()->create(['name' => 'Pemasok Avg Order']);
    $cashier = User::factory()->create(['role' => 'kasir']);
    foreach ([[20000, 30000], [30000, 20000]] as $case => $prices) {
        $product = batchStockProduct('Merah Avg Order '.$case, 'merah');
        foreach ($prices as $price) {
            $purchase = $this->withToken($token)->postJson('/api/v1/purchases', [
                ...batchStockPurchasePayload($supplier->id), 'gross_weight_kg' => '10.000', 'price_per_kg' => $price,
            ])->assertCreated()->json('data');
            $this->withToken($token)->postJson('/api/v1/stock-additions', [
                'batch_id' => $purchase['id'], 'product_id' => $product->id, 'weight_kg' => '10.000',
            ])->assertCreated();
        }
        $transaction = app(TransactionService::class)->create([
            'client_uuid' => $case === 0 ? '30000000-0000-4000-8000-000000000801' : '30000000-0000-4000-8000-000000000802',
            'customer_category' => 'ecer', 'items' => [['product_id' => $product->id, 'weight_kg' => '5.000']],
            'payment_method' => 'cash', 'final_total' => 115000, 'amount_paid' => 115000,
        ], $cashier->id);
        $item = $transaction->items()->firstOrFail();
        expect((int) $item->cogs)->toBe(125000)
            ->and(StockMovement::query()->where('type', 'sale')->where('ref_id', $item->id)->value('lot_id'))->toBeNull();
    }
});

it('rejects stock additions over batch remainder, for another kind, and to a locked batch', function () {
    [$owner, $token] = batchStockOwner();
    $supplier = Supplier::query()->create(['name' => 'Pemasok Add Rules']);
    $red = batchStockProduct('Merah Add Rules', 'merah');
    $white = batchStockProduct('Putih Add Rules', 'putih');
    $purchase = $this->withToken($token)->postJson('/api/v1/purchases', batchStockPurchasePayload($supplier->id))
        ->assertCreated()->json('data');
    $body = ['batch_id' => $purchase['id'], 'product_id' => $red->id, 'weight_kg' => '100.001'];
    $this->withToken($token)->postJson('/api/v1/stock-additions', $body)->assertUnprocessable()->assertJsonValidationErrors(['weight_kg']);
    $body['weight_kg'] = '1.000';
    $body['product_id'] = $white->id;
    $this->withToken($token)->postJson('/api/v1/stock-additions', $body)->assertUnprocessable()->assertJsonValidationErrors(['product_id']);
    Purchase::query()->whereKey($purchase['id'])->update(['sorted_finished_at' => now()]);
    $body['product_id'] = $red->id;
    $this->withToken($token)->postJson('/api/v1/stock-additions', $body)->assertStatus(409);

    $cashier = User::factory()->create(['role' => 'kasir']);
    $this->actingAs($cashier, 'sanctum')->postJson('/api/v1/stock-additions', [
        'batch_id' => $purchase['id'], 'product_id' => $red->id, 'weight_kg' => '1.000',
    ])->assertForbidden();
});

it('backfills provisional cogs at the added batch cost without allocating sales to a batch', function () {
    [$owner, $token] = batchStockOwner();
    $supplier = Supplier::query()->create(['name' => 'Pemasok Backfill']);
    $product = batchStockProduct('Merah Backfill', 'merah');
    $product->forceFill(['last_unit_cost' => 20000])->save();
    $purchase = $this->withToken($token)->postJson('/api/v1/purchases', [
        ...batchStockPurchasePayload($supplier->id), 'price_per_kg' => 22000,
    ])->assertCreated()->json('data');
    $cashier = User::factory()->create(['role' => 'kasir']);
    $transaction = app(TransactionService::class)->create([
        'client_uuid' => '30000000-0000-4000-8000-000000000777', 'customer_category' => 'ecer',
        'items' => [['product_id' => $product->id, 'weight_kg' => '2.000']],
        'payment_method' => 'cash', 'final_total' => 54000, 'amount_paid' => 54000,
    ], $cashier->id);
    $item = $transaction->items()->firstOrFail();
    expect($transaction->hpp_provisional)->toBeTrue()->and((int) $item->cogs)->toBe(40000);
    expect(StockMovement::query()->where('type', 'sale')->where('ref_id', $item->id)->value('lot_id'))->toBeNull();

    $this->withToken($token)->postJson('/api/v1/stock-additions', [
        'batch_id' => $purchase['id'], 'product_id' => $product->id, 'weight_kg' => '2.000',
    ])->assertCreated();

    $transaction->refresh();
    $item->refresh();
    expect($transaction->hpp_provisional)->toBeFalse()->and((int) $item->cogs)->toBe(44000)
        ->and($item->shortfall_resolved_kg)->toBe('2.000')
        ->and(app(\App\Services\AverageCostService::class)->stock($product)['stock_kg'])->toBe('0.000')
        ->and(app(\App\Services\AverageCostService::class)->stock($product)['stock_value'])->toBe(0);
    expect(AuditLog::query()->where('action', 'transaction.hpp_backfilled')->firstOrFail()->payload)
        ->toMatchArray(['transaction_item_id' => $item->id, 'weight_kg' => '2.000', 'cogs_before' => 40000, 'cogs_after' => 44000, 'cogs_delta' => 4000]);
    $this->withToken($token)->getJson('/api/v1/transactions/'.$transaction->id)
        ->assertOk()->assertJsonPath('data.hpp_provisional', false)->assertJsonPath('data.has_shortfall', false);

    app(TransactionService::class)->void($transaction, $owner->id, 'Batalkan transaksi provisional terkoreksi');
    expect(app(\App\Services\AverageCostService::class)->stock($product)['stock_kg'])->toBe('2.000')
        ->and(app(\App\Services\AverageCostService::class)->stock($product)['stock_value'])->toBe(44000);
});

it('keeps sale and void movements at product level without batch allocation', function () {
    [$owner, $ownerToken] = batchStockOwner();
    $supplier = Supplier::query()->create(['name' => 'Pemasok No Sale Allocation']);
    $product = batchStockProduct('Merah No Sale Allocation', 'merah');
    $purchase = $this->withToken($ownerToken)->postJson('/api/v1/purchases', batchStockPurchasePayload($supplier->id))
        ->assertCreated()->json('data');
    $lot = StockLot::query()->create([
        'purchase_id' => $purchase['id'], 'product_id' => $product->id, 'weight_kg' => '10.000',
        'sorted_at' => now()->toDateString(), 'unit_cost' => 21000, 'created_by' => $owner->id,
    ]);
    StockMovement::query()->create([
        'product_id' => $product->id, 'lot_id' => $lot->id, 'type' => 'in_sort', 'weight_kg' => '10.000',
        'value_delta' => 210000, 'occurred_at' => now(),
    ]);
    $cashier = User::factory()->create(['role' => 'kasir']);
    $sale = app(TransactionService::class)->create([
        'client_uuid' => '30000000-0000-4000-8000-000000000778', 'customer_category' => 'ecer',
        'items' => [['product_id' => $product->id, 'weight_kg' => '2.000']], 'payment_method' => 'cash',
        'final_total' => 54000, 'amount_paid' => 54000,
    ], $cashier->id);
    $saleMovement = StockMovement::query()->where('type', 'sale')->where('ref_id', $sale->items->first()->id)->firstOrFail();
    expect($saleMovement->lot_id)->toBeNull();

    app(TransactionService::class)->void($sale, $owner->id, 'Tes koreksi transaksi');
    expect(StockMovement::query()->where('type', 'void_return')->firstOrFail()->lot_id)->toBeNull();
});
