<?php

use App\Models\Product;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function purchaseListingFixture(string $username = 'listing-owner'): array
{
    $owner = User::factory()->create(['role' => 'owner', 'username' => $username]);
    $supplier = Supplier::query()->create(['name' => 'Pemasok '.$username]);
    $product = Product::query()->create([
        'name' => 'Produk '.$username, 'price_retail' => 10000, 'price_3kg' => 9000, 'price_5kg' => 8000,
    ]);

    return [$owner, $owner->createToken('purchase-list')->plainTextToken, $supplier, $product];
}

function listingPurchase(Supplier $supplier, User $owner, string $status, string $date, string $gross, array $lots = []): Purchase
{
    [$whole, $fraction] = array_pad(explode('.', $gross, 2), 2, '');
    $grossMilli = ((int) $whole * 1000) + (int) str_pad(substr($fraction, 0, 3), 3, '0');
    $purchase = $supplier->purchases()->create([
        'purchased_at' => $date,
        'gross_weight_kg' => $gross,
        'price_per_kg' => 10000,
        'total_cost' => intdiv($grossMilli * 10000, 1000),
        'notes' => 'Uji '.$status,
        'status' => $status,
        'created_by' => $owner->id,
        'cost_per_clean_kg' => $status === 'finalized' ? 12500 : null,
        'clean_weight_kg' => $status === 'finalized' ? '4.000' : null,
        'finalized_at' => $status === 'finalized' ? now() : null,
    ]);
    DB::table('purchases')->where('id', $purchase->id)->update(['purchased_at' => $date]);

    foreach ($lots as $weight) {
        $purchase->stockLots()->create([
            'product_id' => Product::query()->where('name', 'Produk '.$owner->username)->value('id'),
            'clean_weight_kg' => $weight,
            'cleaned_at' => $date,
            'unit_cost' => $status === 'finalized' ? 12500 : null,
        ]);
    }

    return $purchase;
}

it('PL-01 caps page size and returns the exact pagination metadata', function () {
    [$owner, $token, $supplier] = purchaseListingFixture();
    for ($i = 1; $i <= 55; $i++) {
        listingPurchase($supplier, $owner, 'open', '2026-10-'.sprintf('%02d', (($i - 1) % 28) + 1), '10.000');
    }

    $this->withToken($token)->getJson('/api/v1/purchases?per_page=500')->assertOk()
        ->assertJsonCount(50, 'data')->assertJsonPath('meta.total', 55)
        ->assertJsonPath('meta.current_page', 1)->assertJsonPath('meta.last_page', 2)->assertJsonPath('meta.per_page', 50);

    foreach (['0', 'abc'] as $value) {
        $this->withToken($token)->getJson('/api/v1/purchases?per_page='.$value)->assertUnprocessable();
    }
    $this->withToken($token)->getJson('/api/v1/purchases?per_page=')->assertOk()->assertJsonPath('meta.per_page', 20);
});

it('PL-02 filters inclusive purchase dates and status and validates the range', function () {
    [$owner, $token, $supplier] = purchaseListingFixture('listing-dates');
    listingPurchase($supplier, $owner, 'open', '2026-10-01', '10.000');
    listingPurchase($supplier, $owner, 'finalized', '2026-10-03', '20.000');
    listingPurchase($supplier, $owner, 'open', '2026-10-04', '30.000');
    $response = $this->withToken($token)->getJson('/api/v1/purchases?from=2026-10-01&to=2026-10-03&status=finalized');
    $response
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.status', 'finalized');
    $this->withToken($token)->getJson('/api/v1/purchases?from=2026-10-01')->assertUnprocessable();
    $this->withToken($token)->getJson('/api/v1/purchases?from=2026-10-03&to=2026-10-01')->assertUnprocessable();
    $this->withToken($token)->getJson('/api/v1/purchases?from=2025-01-01&to=2026-01-02')->assertUnprocessable();
    $this->withToken($token)->getJson('/api/v1/purchases?status=bad')->assertUnprocessable();
});

it('PL-03 calculates date-only summary independently of status and pagination', function () {
    [$owner, $token, $supplier] = purchaseListingFixture('listing-summary');
    listingPurchase($supplier, $owner, 'open', '2026-10-01', '10.125');
    listingPurchase($supplier, $owner, 'finalized', '2026-10-02', '20.250');
    listingPurchase($supplier, $owner, 'open', '2026-10-03', '30.500');

    $base = $this->withToken($token)->getJson('/api/v1/purchases?from=2026-10-01&to=2026-10-03&per_page=1&status=open')
        ->assertOk()->json('summary');
    $secondPage = $this->withToken($token)->getJson('/api/v1/purchases?from=2026-10-01&to=2026-10-03&per_page=2&page=2&status=finalized')
        ->assertOk()->json('summary');

    expect($base)->toBe($secondPage)
        ->and($base)->toBe([
            'count' => 3, 'open_count' => 2, 'finalized_count' => 1,
            'gross_weight_kg' => '60.875', 'open_gross_weight_kg' => '40.625',
        ]);

    $this->withToken($token)->getJson('/api/v1/purchases?from=2025-01-01&to=2025-01-01')
        ->assertOk()->assertJsonPath('summary.count', 0)->assertJsonPath('summary.gross_weight_kg', '0.000');
});

it('PL-04 omits lots by default and includes lots only when requested', function () {
    [$owner, $token, $supplier] = purchaseListingFixture('listing-lots');
    listingPurchase($supplier, $owner, 'open', '2026-10-10', '10.000', ['4.000']);
    $this->withToken($token)->getJson('/api/v1/purchases')->assertOk()
        ->assertJsonPath('data.0.lots_count', 1)->assertJsonPath('data.0.lots_total_kg', '4.000')
        ->assertJsonMissingPath('data.0.lots');
    $this->withToken($token)->getJson('/api/v1/purchases?include=lots')->assertOk()
        ->assertJsonCount(1, 'data.0.lots')->assertJsonPath('data.0.lots.0.clean_weight_kg', '4.000');
});

it('PL-05 keeps purchase list query counts constant as rows grow', function () {
    [$owner, $token, $supplier] = purchaseListingFixture('listing-queries');
    $measure = function (int $rows, bool $includeLots) use ($owner, $token, $supplier): int {
        Purchase::query()->delete();
        for ($i = 1; $i <= $rows; $i++) {
            listingPurchase($supplier, $owner, 'open', '2026-10-'.sprintf('%02d', (($i - 1) % 28) + 1), '10.000');
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        Sanctum::actingAs($owner);
        $suffix = $includeLots ? '?include=lots' : '';
        test()->getJson('/api/v1/purchases'.$suffix)->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    foreach ([false, true] as $includeLots) {
        expect($measure(5, $includeLots))->toBe($measure(30, $includeLots));
    }
});

it('PL-06 denies purchase listing to cashiers', function () {
    [$owner, $token] = purchaseListingFixture('listing-auth-owner');
    $cashier = User::factory()->create(['role' => 'kasir', 'username' => 'listing-auth-cashier']);

    $this->actingAs($cashier, 'sanctum')->getJson('/api/v1/purchases')->assertForbidden();
});
