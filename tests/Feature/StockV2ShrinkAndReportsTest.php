<?php

use App\Models\Expense;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\StockLot;
use App\Services\StockAdditionService;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\User;
use App\Services\PurchaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Carbon\Carbon::setTestNow(Carbon\Carbon::parse('2026-11-02 12:00:00', 'Asia/Jakarta'));
});

afterEach(function (): void {
    Carbon\Carbon::setTestNow();
});

function z04Owner(string $name): User
{
    return User::factory()->create(['role' => 'owner', 'username' => 'z04-'.$name]);
}

function z04Product(string $name, string $jenis = 'merah'): Product
{
    return Product::query()->create(['name' => $name, 'jenis' => $jenis, 'price_retail' => 27000, 'price_5kg' => 24000]);
}

function z04Purchase(User $owner, string $date, string $gross, int $price): Purchase
{
    $supplier = Supplier::query()->create(['name' => 'Supplier '.$date.' '.$price]);
    $seq = Purchase::withTrashed()->count() + 1;

    return Purchase::query()->create([
        'code' => 'KLK-'.Carbon\Carbon::parse($date)->format('ymd').'-'.str_pad((string) $seq, 3, '0', STR_PAD_LEFT),
        'supplier_id' => $supplier->id, 'purchased_at' => $date, 'jenis' => 'merah',
        'gross_weight_kg' => $gross, 'price_per_kg' => $price,
        'total_cost' => (int) bcmul($gross, (string) $price, 0), 'created_by' => $owner->id,
    ]);
}

function z04Lot(Purchase $purchase, Product $product, User $owner, string $weight, string $sortedAt): StockLot
{
    $lot = StockLot::query()->create([
        'purchase_id' => $purchase->id, 'product_id' => $product->id, 'weight_kg' => $weight,
        'sorted_at' => $sortedAt, 'unit_cost' => $purchase->price_per_kg, 'created_by' => $owner->id,
    ]);
    StockMovement::query()->create([
        'product_id' => $product->id, 'lot_id' => $lot->id, 'type' => 'in_sort', 'weight_kg' => $weight,
        'value_delta' => (int) bcmul($weight, (string) $purchase->price_per_kg, 0), 'occurred_at' => now(), 'created_by' => $owner->id,
    ]);

    return $lot;
}

function z04Sale(User $cashier, Product $product, string $weight, int $revenue, int $cogs, string $uuid, string $status = 'completed'): Transaction
{
    static $invoice = 0;
    $invoice++;
    $transaction = Transaction::query()->create([
        'invoice_no' => 'Z04-'.str_pad((string) $invoice, 4, '0', STR_PAD_LEFT), 'client_uuid' => $uuid,
        'cashier_id' => $cashier->id, 'customer_category' => 'ecer', 'subtotal' => $revenue,
        'discount' => 0, 'total' => $revenue, 'amount_paid' => $revenue, 'payment_method' => 'cash',
        'status' => $status, 'occurred_at' => now(),
    ]);
    TransactionItem::query()->create([
        'transaction_id' => $transaction->id, 'product_id' => $product->id, 'product_name' => $product->name,
        'weight_kg' => $weight, 'price_tier' => 'retail', 'price_per_kg' => $revenue,
        'line_total' => $revenue, 'cogs' => $cogs,
    ]);
    if ($status === 'completed') {
        StockMovement::query()->create([
            'product_id' => $product->id, 'type' => 'sale', 'weight_kg' => '-'.$weight,
            'value_delta' => -$cogs, 'ref_type' => Transaction::class, 'ref_id' => $transaction->id,
            'occurred_at' => now(), 'created_by' => $cashier->id,
        ]);
    }

    return $transaction;
}

function z04PurchaseDetail(Purchase $purchase): array
{
    return app(PurchaseService::class)->present($purchase->fresh())->toArray();
}

function z04Addition(Purchase $purchase, Product $product, User $owner, string $weight): void
{
    app(StockAdditionService::class)->create([
        'batch_id' => $purchase->id, 'product_id' => $product->id, 'weight_kg' => $weight,
    ], $owner->id);
}

it('SS-01 returns batch additions and remainder without deriving sales or shrink by batch', function () {
    $owner = z04Owner('ss01');
    $cashier = User::factory()->create(['role' => 'kasir', 'username' => 'z04-cash-ss01']);
    $purchase = z04Purchase($owner, '2026-11-01', '100.000', 20000);
    $products = [z04Product('A ss01'), z04Product('B ss01'), z04Product('C ss01')];
    foreach (['40.000', '30.000', '28.000'] as $i => $weight) {
        z04Addition($purchase, $products[$i], $owner, $weight);
    }
    foreach ([['40.000', 800000], ['30.000', 600000], ['25.000', 500000]] as $i => [$weight,$cogs]) {
        z04Sale($cashier, $products[$i], $weight, $cogs, $cogs, 'z04-ss01-'.$i);
    }
    $detail = z04PurchaseDetail($purchase);
    expect($detail['added_weight_kg'])->toBe('98.000')->and($detail['remaining_weight_kg'])->toBe('2.000')
        ->and($detail['cleaning_shrink_kg'])->toBe('0.000')
        ->and($detail)->not->toHaveKey('sold_kg')->not->toHaveKey('susut_kg');
});

it('SS-02 sales never change metrics for either of two batches of the same product', function () {
    $owner = z04Owner('ss02');
    $cashier = User::factory()->create(['role' => 'kasir', 'username' => 'z04-cash-ss02']);
    $p1 = z04Purchase($owner, '2026-11-01', '20.000', 10000);
    $p2 = z04Purchase($owner, '2026-11-03', '20.000', 12000);
    $product = z04Product('Shared SS02');
    z04Addition($p1, $product, $owner, '20.000');
    z04Addition($p2, $product, $owner, '20.000');
    z04Sale($cashier, $product, '25.000', 300000, 250000, 'z04-ss02');
    foreach ([$p1, $p2] as $purchase) {
        $detail = z04PurchaseDetail($purchase);
        expect($detail['added_weight_kg'])->toBe('20.000')->and($detail['remaining_weight_kg'])->toBe('0.000')
            ->and($detail)->not->toHaveKey('sold_kg')->not->toHaveKey('susut_kg');
    }
});

it('SS-03 void sales do not add derived sales metrics to batch responses', function () {
    $owner = z04Owner('ss03');
    $cashier = User::factory()->create(['role' => 'kasir', 'username' => 'z04-cash-ss03']);
    $product = z04Product('SS03');
    $purchase = z04Purchase($owner, '2026-11-01', '20.000', 10000);
    z04Addition($purchase, $product, $owner, '10.000');
    z04Sale($cashier, $product, '7.000', 70000, 70000, 'z04-ss03', 'void');
    expect(z04PurchaseDetail($purchase))->not->toHaveKey('sold_kg')->not->toHaveKey('susut_kg');
});

it('SS-04 a later addition resolves provisional HPP at product cost without batch sale allocation', function () {
    $owner = z04Owner('ss04');
    $cashier = User::factory()->create(['role' => 'kasir', 'username' => 'z04-cash-ss04']);
    $product = z04Product('SS04');
    z04Sale($cashier, $product, '2.000', 50000, 0, 'z04-ss04');
    $purchase = z04Purchase($owner, '2026-11-01', '10.000', 10000);
    z04Addition($purchase, $product, $owner, '10.000');
    expect(z04PurchaseDetail($purchase)['added_weight_kg'])->toBe('10.000')
        ->and(z04PurchaseDetail($purchase))->not->toHaveKey('sold_kg');
});

it('SS-05 records cleaning loss only when the owner finishes sorting', function () {
    $owner = z04Owner('ss05');
    $token = $owner->createToken('ss05')->plainTextToken;
    $product = z04Product('SS05');
    $purchase = z04Purchase($owner, '2026-11-01', '100.000', 20000);
    z04Addition($purchase, $product, $owner, '98.000');
    $this->withToken($token)->postJson('/api/v1/purchases/'.$purchase->id.'/finish-sorting')->assertOk()
        ->assertJsonPath('data.cleaning_shrink_kg', '2.000')->assertJsonPath('data.cleaning_loss_value', 40000);
    $this->withToken($token)->getJson('/api/v1/reports/finance?period=daily&date=2026-11-02')->assertOk()
        ->assertJsonPath('data.cleaning_loss_value', 40000)->assertJsonPath('data.daily.0.cleaning_loss_value', 40000);
    $this->withToken($token)->postJson('/api/v1/purchases/'.$purchase->id.'/close')->assertNotFound();
});

it('SS-06 counts the remaining weight of unlocked batches as unsorted stock', function () {
    $owner = z04Owner('ss06');
    $token = $owner->createToken('ss06')->plainTextToken;
    $product = z04Product('SS06');
    $open = z04Purchase($owner, '2026-11-01', '100.000', 20000);
    z04Addition($open, $product, $owner, '70.000');
    $closed = z04Purchase($owner, '2026-11-02', '50.000', 20000);
    z04Addition($closed, $product, $owner, '20.000');
    $this->withToken($token)->getJson('/api/v1/reports/stock?from=2026-11-01&to=2026-11-03')->assertOk()
        ->assertJsonMissingPath('data.warehouse_kg')->assertJsonPath('data.pending_gross_kg', '60.000');
});

it('SS-07 reports only event-based inventory and shrink metrics for a selected period', function () {
    $owner = z04Owner('ss07');
    $token = $owner->createToken('ss07')->plainTextToken;
    z04Purchase($owner, '2026-11-01', '10.000', 10000);
    z04Purchase($owner, '2026-11-02', '30.000', 20000);
    $this->withToken($token)->getJson('/api/v1/reports/stock?from=2026-11-01&to=2026-11-02')->assertOk()
        ->assertJsonPath('data.incoming_gross_kg', '40.000')->assertJsonPath('data.pending_gross_kg', '40.000')
        ->assertJsonMissingPath('data.avg_purchase_price_per_kg')->assertJsonMissingPath('data.susut_value_estimate');
    $this->withToken($token)->getJson('/api/v1/reports/stock?from=2026-12-01&to=2026-12-02')->assertOk()
        ->assertJsonPath('data.display_shrink_value', 0)->assertJsonMissingPath('data.warehouse_kg');
});

it('SS-08 keeps client susut informational and out of net profit', function () {
    $owner = z04Owner('ss08');
    $token = $owner->createToken('ss08')->plainTextToken;
    $cashier = User::factory()->create(['role' => 'kasir', 'username' => 'z04-cash-ss08']);
    $purchase = z04Purchase($owner, '2026-11-01', '20.000', 10000);
    $product = z04Product('SS08');
    z04Addition($purchase, $product, $owner, '10.000');
    z04Sale($cashier, $product, '2.000', 50000, 20000, 'z04-ss08');
    $before = $this->withToken($token)->getJson('/api/v1/reports/finance?from=2026-11-01&to=2026-11-01')->assertOk()->json('data.net_profit');
    z04Purchase($owner, '2026-11-01', '20.000', 10000);
    $this->withToken($token)->getJson('/api/v1/reports/finance?from=2026-11-01&to=2026-11-01')->assertOk()->assertJsonPath('data.net_profit', $before);
});

it('RP-01 calculates profit from sales, COGS, shrink events and operating expenses', function () {
    $owner = z04Owner('rp01');
    $token = $owner->createToken('rp01')->plainTextToken;
    $cashier = User::factory()->create(['role' => 'kasir', 'username' => 'z04-cash-rp01']);
    $purchase = z04Purchase($owner, '2026-11-01', '100.000', 20000);
    $products = [z04Product('A rp01'), z04Product('B rp01'), z04Product('C rp01')];
    foreach (['40.000', '30.000', '28.000'] as $i => $weight) {
        z04Addition($purchase, $products[$i], $owner, $weight);
    }
    foreach ([['40.000', 800000], ['30.000', 600000], ['25.000', 500000]] as $i => [$weight,$cogs]) {
        z04Sale($cashier, $products[$i], $weight, ($i === 0 ? 1040000 : ($i === 1 ? 780000 : 650000)), $cogs, 'z04-rp01-'.$i);
    }
    Expense::query()->create(['name' => 'Operasional', 'category' => 'lainnya', 'amount' => 50000, 'spent_at' => '2026-11-02', 'created_by' => $owner->id]);
    $this->withToken($token)->getJson('/api/v1/reports/finance?from=2026-11-01&to=2026-11-03')->assertOk()
        ->assertJsonPath('data.net_profit', 520000)->assertJsonPath('data.gross_profit', 570000)
        ->assertJsonPath('data.operating_expenses', 50000)->assertJsonPath('data.cleaning_loss_value', 0);
    $this->withToken($token)->getJson('/api/v1/reports/stock?from=2026-11-01&to=2026-11-03')->assertOk()
        ->assertJsonPath('data.sold_kg', '95.000')->assertJsonPath('data.cleaning_shrink_value', 0)
        ->assertJsonPath('data.shrink_value', 0);
});

it('RP-02 preserves the lifetime accounting identity for a deterministic ledger fixture', function () {
    $owner = z04Owner('rp02');
    $token = $owner->createToken('rp02')->plainTextToken;
    $cashier = User::factory()->create(['role' => 'kasir', 'username' => 'z04-cash-rp02']);
    mt_srand(3404);
    for ($i = 0; $i < 7; $i++) {
        $gross = mt_rand(30, 80);
        $unsorted = mt_rand(1, min(8, $gross - 1));
        $sorted = $gross - $unsorted;
        $price = mt_rand(12, 35) * 1000;
        $sold = mt_rand(0, $sorted);
        $purchase = z04Purchase($owner, '2026-11-01', $gross.'.000', $price);
        $product = z04Product('RP02 '.$i);
        z04Addition($purchase, $product, $owner, $sorted.'.000');
        if ($sold > 0) {
            z04Sale($cashier, $product, $sold.'.000', $sold * ($price + 5000), $sold * $price, 'z04-rp02-'.$i);
        }
    }
    $finance = $this->withToken($token)->getJson('/api/v1/reports/finance?from=2026-11-01&to=2026-11-30')->assertOk()->json('data');
    $stock = $this->withToken($token)->getJson('/api/v1/reports/stock?from=2026-11-01&to=2026-11-30')->assertOk()->json('data');
    expect($finance['reconciliation_difference'])->toBe(0)
        ->and($finance['reconciliation_mismatch'])->toBeFalse()
        ->and($stock)->not->toHaveKey('warehouse_kg');
});

it('RP-03 returns exactly the stock report top-level and product keys', function () {
    $owner = z04Owner('rp03');
    $token = $owner->createToken('rp03')->plainTextToken;
    z04Product('RP03');
    $data = $this->withToken($token)->getJson('/api/v1/reports/stock?from=2026-11-01&to=2026-11-01')->assertOk()->json('data');
    expect(array_keys($data))->toBe(['from', 'to', 'incoming_gross_kg', 'pending_gross_kg', 'clean_kg', 'cleaning_shrink_kg', 'cleaning_shrink_value', 'sold_kg', 'display_shrink_kg', 'display_shrink_value', 'shrink_kg', 'shrink_value', 'stock_ready_kg', 'display_value', 'unsorted_value', 'stock_value', 'products']);
    expect(array_keys($data['products'][0]))->toBe(['product_id', 'name', 'jenis', 'is_active', 'stock_kg', 'shortfall_kg', 'stock_value', 'avg_cost', 'sold_kg', 'display_shrink_kg', 'display_shrink_value', 'last_count_date']);
});

it('RP-04 reconciles cleaning loss total to daily rows and their net profit sum', function () {
    $owner = z04Owner('rp04');
    $token = $owner->createToken('rp04')->plainTextToken;
    foreach ([['2026-11-01', '10.000', '10000'], ['2026-11-02', '20.000', '10000']] as [$date,$gross,$price]) {
        $purchase = z04Purchase($owner, $date, $gross, (int) $price);
        $product = z04Product('RP04 '.$date);
        z04Lot($purchase, $product, $owner, '5.000', $date);
    }
    $data = $this->withToken($token)->getJson('/api/v1/reports/finance?from=2026-11-01&to=2026-11-02')->assertOk()->json('data');
    expect($data['cleaning_loss_value'])->toBe(array_sum(array_column($data['daily'], 'cleaning_loss_value')))
        ->and($data['net_profit'])->toBe(array_sum(array_column($data['daily'], 'net_profit')));
});

it('RP-05 returns stock rows and totals with display and unsorted inventory semantics', function () {
    $owner = z04Owner('rp05');
    $token = $owner->createToken('rp05')->plainTextToken;
    $product = z04Product('RP05');
    $purchase = z04Purchase($owner, '2026-11-01', '10.000', 10000);
    z04Addition($purchase, $product, $owner, '6.000');
    $this->withToken($token)->getJson('/api/v1/stock')->assertOk()->assertJsonPath('totals.display_kg', '6.000')
        ->assertJsonPath('totals.unsorted_kg', '4.000')->assertJsonMissingPath('totals.warehouse_kg')
        ->assertJsonPath('data.0.product_id', $product->id);
});

it('RP-06 keeps purchase totals independent of expenses and sales', function () {
    $owner = z04Owner('rp06');
    $token = $owner->createToken('rp06')->plainTextToken;
    $cashier = User::factory()->create(['role' => 'kasir', 'username' => 'z04-cash-rp06']);
    $purchase = z04Purchase($owner, '2026-11-01', '10.000', 10000);
    $product = z04Product('RP06');
    z04Addition($purchase, $product, $owner, '10.000');
    $before = $this->withToken($token)->getJson('/api/v1/reports/finance?from=2026-11-01&to=2026-11-01')->assertOk()->json('data.purchases_total');
    Expense::query()->create(['name' => 'Biaya', 'category' => 'lainnya', 'amount' => 20000, 'spent_at' => '2026-11-01', 'created_by' => $owner->id]);
    z04Sale($cashier, $product, '1.000', 30000, 10000, 'z04-rp06');
    $this->withToken($token)->getJson('/api/v1/reports/finance?from=2026-11-01&to=2026-11-01')->assertOk()->assertJsonPath('data.purchases_total', $before);
});
