<?php

use App\Models\Expense;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\StockCount;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function exampleFixture(): array
{
    config(['app.debug' => false]);
    Carbon::setTestNow(Carbon::parse('2026-10-27 10:00:00', 'Asia/Jakarta'));
    $demo = \Tests\Support\DemoData::seed();
    $owner = $demo['owner'];
    Sanctum::actingAs($owner);
    return [...$demo, 'primary_purchase' => $demo['primary_purchase'],
        'active_count_old' => $demo['count_old'], 'active_count_latest' => $demo['count_latest'], 'cancelled_count' => $demo['count_cancelled']];
}

function exampleRouteQuery(string $path): array
{
    return match (true) {
        $path === 'reports/finance' => ['period' => 'monthly', 'month' => '2026-10'],
        in_array($path, ['reports/sales-by-product', 'reports/stock'], true) => ['from' => '2026-10-01', 'to' => '2026-10-27'],
        $path === 'reports/shifts' => ['date' => '2026-10-27'],
        $path === 'transactions' => ['from' => '2026-10-01', 'to' => '2026-10-27'],
        $path === 'expenses' => ['from' => '2026-10-01', 'to' => '2026-10-27'],
        $path === 'audit-logs' => ['from' => '2026-10-01', 'to' => '2026-10-27'],
        default => [],
    };
}

function exampleRouteBody(string $method, string $path, array $fixture): array
{
    $product = $fixture['product'];
    $supplier = $fixture['supplier'];

    return match (true) {
        $path === 'auth/login' => ['username' => 'owner', 'password' => 'password'],
        $path === 'products' && $method === 'POST' => [
            'name' => 'Produk Contoh Baru', 'jenis' => 'merah', 'price_retail' => 27000, 'price_5kg' => 23000,
        ],
        preg_match('#^products/\{product\}$#', $path) === 1 && $method === 'PUT' => [
            'name' => 'Merah A', 'jenis' => 'merah', 'price_retail' => 28000, 'price_5kg' => 24000,
        ],
        preg_match('#^products/\{product\}/active$#', $path) === 1 => ['is_active' => true],
        $path === 'suppliers' && $method === 'POST' => ['name' => 'Pemasok Contoh Baru', 'phone' => '081200000001', 'note' => 'Contoh API'],
        preg_match('#^suppliers/\{supplier\}$#', $path) === 1 && $method === 'PUT' => ['name' => $supplier->name],
        $path === 'purchases' && $method === 'POST' => [
            'supplier_id' => $supplier->id, 'purchased_at' => '2026-10-27', 'jenis' => 'merah', 'gross_weight_kg' => '10.000', 'price_per_kg' => 17000,
            'notes' => 'Contoh kulak API',
        ],
        preg_match('#^purchases/\{purchase\}$#', $path) === 1 && $method === 'PUT' => [
            'supplier_id' => $supplier->id, 'purchased_at' => '2026-10-26', 'gross_weight_kg' => '20.000', 'price_per_kg' => 15000,
            'notes' => 'Contoh perubahan',
        ],
        $path === 'stock-counts' && $method === 'POST' => [
            'purchase_id' => $fixture['primary_purchase']->id, 'product_id' => $product->id,
            'counted_weight_kg' => '0.000', 'counted_at' => '2026-10-27 10:00:00', 'notes' => 'Contoh hitung ulang',
        ],
        $path === 'expenses' && in_array($method, ['POST', 'PUT'], true) => [
            'name' => 'Pengeluaran contoh', 'category' => 'lainnya', 'amount' => 10000, 'spent_at' => '2026-10-27', 'notes' => 'Contoh API',
        ],
        preg_match('#^expenses/\{expense\}$#', $path) === 1 && $method === 'DELETE' => ['reason' => 'Contoh penghapusan'],
        $path === 'transactions/quote' => [
            'customer_category' => 'ecer', 'items' => [['product_id' => $product->id, 'weight_kg' => '0.500']], 'final_total' => null,
        ],
        $path === 'transactions' && $method === 'POST' => [
            'client_uuid' => 'd1000000-0000-4000-8000-000000000001', 'customer_category' => 'ecer',
            'customer_name' => 'Pelanggan Contoh', 'items' => [['product_id' => $product->id, 'weight_kg' => '1.000']],
            'payment_method' => 'cash', 'final_total' => 27500, 'amount_paid' => 30000,
        ],
        preg_match('#^transactions/\{transaction\}/void$#', $path) === 1 => ['reason' => 'Contoh void transaksi'],
        default => [],
    };
}

function exampleRouteParameters(string $method, string $path, array $fixture): array
{
    $purchase = $fixture['primary_purchase'];

    return [
        'product' => $fixture['product']->id,
        'supplier' => $fixture['supplier']->id,
        'purchase' => $purchase->id,
        'expense' => $fixture['expense']->id,
        'transaction' => $fixture['transaction']->id,
    ];
}

function exampleSafeValue(mixed $value, ?string $key = null): mixed
{
    if ($key !== null && preg_match('/token|password|secret|app_?key|authorization/i', $key) === 1) {
        return 'REDACTED';
    }
    if (! is_array($value)) {
        if (is_string($value)) {
            return preg_replace('/ExamplesTest\.php:\d+/', 'ExamplesTest.php:<line>', $value);
        }
        return $value;
    }
    if (array_key_exists('file', $value) && array_key_exists('line', $value)) {
        $value['file'] = '<runtime-file>';
        $value['line'] = 0;
    }
    foreach ($value as $childKey => $childValue) {
        if ($childKey === 'trace') {
            continue;
        }
        $value[$childKey] = exampleSafeValue($childValue, is_string($childKey) ? $childKey : null);
    }
    return $value;
}

function buildRouteExamples(array $fixture): array
{
    $examples = [];
    foreach (Route::getRoutes() as $route) {
        if (! str_starts_with($route->uri(), 'api/v1/')) {
            continue;
        }
        $relativePath = substr($route->uri(), strlen('api/v1/'));
        $templatePath = '/api/v1/'.$relativePath;
        foreach ($route->methods() as $method) {
            if ($method === 'HEAD') {
                continue;
            }
            $parameters = exampleRouteParameters($method, $relativePath, $fixture);
            $actualPath = preg_replace_callback('/\{([^}]+)\}/', static fn (array $matches): string => (string) ($parameters[$matches[1]] ?? 1), $templatePath);
            $query = exampleRouteQuery($relativePath);
            $url = $actualPath.($query === [] ? '' : '?'.http_build_query($query));
            $body = exampleRouteBody($method, $relativePath, $fixture);
            $requestData = ['method' => $method, 'path' => $actualPath, 'query' => $query, 'body' => exampleSafeValue($body)];

            DB::beginTransaction();
            try {
                $response = test()->json($method, $url, $body, ['Accept' => 'application/json']);
                $responseBody = $response->getContent() === '' ? null : (json_decode($response->getContent(), true) ?? $response->getContent());
                $examples[$method.'__'.str_replace('/', '_', str_replace(['{', '}'], '', $relativePath)).'.json'] = [
                    'request' => $requestData,
                    'response' => ['status' => $response->getStatusCode(), 'body' => exampleSafeValue($responseBody)],
                ];
            } finally {
                DB::rollBack();
            }

            if (in_array($method, ['POST', 'PUT'], true)) {
                DB::beginTransaction();
                try {
                    $invalid = test()->json($method, $url, [], ['Accept' => 'application/json']);
                    $invalidBody = $invalid->getContent() === '' ? null : (json_decode($invalid->getContent(), true) ?? $invalid->getContent());
                    if ($invalid->getStatusCode() === 422) {
                        $invalidKey = $method.'__'.str_replace('/', '_', str_replace(['{', '}'], '', $relativePath)).'__422.json';
                        $examples[$invalidKey] = [
                            'request' => ['method' => $method, 'path' => $actualPath, 'query' => $query, 'body' => []],
                            'response' => ['status' => 422, 'body' => exampleSafeValue($invalidBody)],
                        ];
                    }
                } finally {
                    DB::rollBack();
                }
            }
        }
    }
    ksort($examples);
    return $examples;
}

/** @return array<string, array{method:string,path:string,query?:array,body?:array,role?:string,status:int}> */
function namedExampleScenarios(array $fixture): array
{
    $batch = $fixture['primary_purchase'];
    $countProduct = $fixture['product'];
    $expense = $fixture['expense'];
    $countOld = $fixture['active_count_old'];
    $countLatest = $fixture['active_count_latest'];
    $missing = 999999;
    $purchasePath = fn ($id, string $tail = '') => "/api/v1/purchases/{$id}{$tail}";

    return [
        'GET__expenses__list_no_filter.json' => ['method'=>'GET','path'=>'/api/v1/expenses','status'=>200],
        'GET__expenses__q.json' => ['method'=>'GET','path'=>'/api/v1/expenses','query'=>['q'=>'Plastik'],'status'=>200],
        'GET__expenses__from_to.json' => ['method'=>'GET','path'=>'/api/v1/expenses','query'=>['from'=>'2026-10-01','to'=>'2026-10-27'],'status'=>200],
        'GET__expenses__per_page_1.json' => ['method'=>'GET','path'=>'/api/v1/expenses','query'=>['per_page'=>1],'status'=>200],
        'POST__expenses__create_without_category.json' => ['method'=>'POST','path'=>'/api/v1/expenses','body'=>['name'=>'Contoh pengeluaran tanpa kategori','amount'=>1000,'spent_at'=>'2026-10-27'],'status'=>422],
        'PUT__expenses_expense__update.json' => ['method'=>'PUT','path'=>"/api/v1/expenses/{$expense->id}",'body'=>['name'=>'Pengeluaran diperbarui','category'=>'lainnya','amount'=>12000,'spent_at'=>'2026-10-27'],'status'=>200],
        'DELETE__expenses_expense__delete__200.json' => ['method'=>'DELETE','path'=>"/api/v1/expenses/{$expense->id}",'body'=>['reason'=>'Contoh penghapusan'],'status'=>200],
        'POST__expenses__validation__422.json' => ['method'=>'POST','path'=>'/api/v1/expenses','body'=>[],'status'=>422],
        'GET__expenses__cashier__403.json' => ['method'=>'GET','path'=>'/api/v1/expenses','role'=>'cashier','status'=>403],
        'DELETE__expenses_expense__deleted__404.json' => ['method'=>'DELETE','path'=>"/api/v1/expenses/{$expense->id}",'status'=>404],
        'GET__expenses_suggestions__suggestions.json' => ['method'=>'GET','path'=>'/api/v1/expenses/suggestions','status'=>200],
        'GET__purchases__list_summary.json' => ['method'=>'GET','path'=>'/api/v1/purchases','status'=>200],
        'GET__purchases__sorting_filter.json' => ['method'=>'GET','path'=>'/api/v1/purchases','query'=>['status_sortir'=>'tersortir'],'status'=>200],
        'GET__purchases__jenis_filter.json' => ['method'=>'GET','path'=>'/api/v1/purchases','query'=>['jenis'=>'merah'],'status'=>200],
        'GET__purchases__include_lots.json' => ['method'=>'GET','path'=>'/api/v1/purchases','query'=>['include'=>'lots'],'status'=>200],
        'POST__purchases__batch_100kg__201.json' => ['method'=>'POST','path'=>'/api/v1/purchases','body'=>[
            'supplier_id'=>$fixture['supplier']->id,'purchased_at'=>'2026-10-27','jenis'=>'merah','gross_weight_kg'=>'100.000','price_per_kg'=>21000,'notes'=>'Contoh batch 100 kg',
        ],'status'=>201],
        'GET__purchases_purchase__detail.json' => ['method'=>'GET','path'=>$purchasePath($batch->id),'status'=>200],
        'GET__purchases_purchase__batch_100_55.json' => ['method'=>'GET','path'=>$purchasePath($batch->id),'status'=>200],
        'POST__stock-counts__batch_100kg_red_a_30kg__201.json' => ['method'=>'POST','path'=>'/api/v1/stock-counts','body'=>[
            'purchase_id'=>$batch->id,'product_id'=>$countProduct->id,'counted_weight_kg'=>'30.000','counted_at'=>'2026-10-27 10:32:00','notes'=>'Hasil timbang batch A',
        ],'status'=>201],
        'POST__stock-counts__batch_100kg_red_b_25kg__201.json' => ['method'=>'POST','path'=>'/api/v1/stock-counts','body'=>[
            'purchase_id'=>$batch->id,'product_id'=>$fixture['products'][1]->id,'counted_weight_kg'=>'25.000','counted_at'=>'2026-10-27 10:33:00','notes'=>'Hasil timbang batch B',
        ],'status'=>201],
        'POST__stock-counts__wrong_jenis__422.json' => ['method'=>'POST','path'=>'/api/v1/stock-counts','body'=>['purchase_id'=>$batch->id,'product_id'=>$fixture['products'][3]->id,'counted_weight_kg'=>'1.000','counted_at'=>'2026-10-27 10:31:00'],'status'=>422],
        'PUT__purchases_purchase__batch_has_stock__409.json' => ['method'=>'PUT','path'=>$purchasePath($batch->id),'body'=>['gross_weight_kg'=>'99.000'],'status'=>409],
        'DELETE__purchases_purchase__batch_has_stock__409.json' => ['method'=>'DELETE','path'=>$purchasePath($batch->id),'status'=>409],
        'GET__stock__shortfall.json' => ['method'=>'GET','path'=>'/api/v1/stock','status'=>200],
        'GET__stock__batch_100_55.json' => ['method'=>'GET','path'=>'/api/v1/stock','status'=>200],
        'GET__stock-counts__active_and_cancelled.json' => ['method'=>'GET','path'=>'/api/v1/stock-counts','status'=>200],
        'GET__stock-counts__active.json' => ['method'=>'GET','path'=>'/api/v1/stock-counts','query'=>['status'=>'active'],'status'=>200],
        'GET__stock-counts__cancelled.json' => ['method'=>'GET','path'=>'/api/v1/stock-counts','query'=>['status'=>'cancelled'],'status'=>200],
        'DELETE__stock-counts_stockCount__cancel_response__200.json' => ['method'=>'DELETE','path'=>"/api/v1/stock-counts/{$countLatest->id}",'body'=>['reason'=>'Contoh pembatalan hitung ulang terbaru'],'status'=>200],
        'DELETE__stock-counts_stockCount__cancel_non_last__409.json' => ['method'=>'DELETE','path'=>"/api/v1/stock-counts/{$countOld->id}",'body'=>['reason'=>'Contoh pembatalan lama'],'status'=>409],
        'GET__products_product_price-history__two_rows.json' => ['method'=>'GET','path'=>"/api/v1/products/{$fixture['product']->id}/price-history",'status'=>200],
        'POST__products__is_active_true.json' => ['method'=>'POST','path'=>'/api/v1/products','body'=>['name'=>'Produk Contoh Aktif','jenis'=>'merah','price_retail'=>27000,'price_5kg'=>23000,'is_active'=>true],'status'=>201],
        'GET__reports_stock__report.json' => ['method'=>'GET','path'=>'/api/v1/reports/stock','query'=>['from'=>'2026-10-01','to'=>'2026-10-27'],'status'=>200],
        'GET__reports_finance__report.json' => ['method'=>'GET','path'=>'/api/v1/reports/finance','query'=>['period'=>'monthly','month'=>'2026-10'],'status'=>200],
        'GET__transactions__owner_shortfall.json' => ['method'=>'GET','path'=>'/api/v1/transactions','query'=>['from'=>'2026-10-01','to'=>'2026-10-27'],'status'=>200],
        'GET__transactions_transaction__owner_shortfall.json' => ['method'=>'GET','path'=>"/api/v1/transactions/{$fixture['transaction']->id}",'status'=>200],
    ];
}

function buildNamedScenarioExamples(array $fixture): array
{
    $examples = [];
    foreach (namedExampleScenarios($fixture) as $filename => $scenario) {
        DB::beginTransaction();
        try {
            if (($scenario['role'] ?? 'owner') === 'cashier') {
                Sanctum::actingAs($fixture['cashier']);
            } else {
                Sanctum::actingAs($fixture['owner']);
            }
            $query = $scenario['query'] ?? [];
            $url = $scenario['path'].($query === [] ? '' : '?'.http_build_query($query));
            $response = test()->json($scenario['method'], $url, $scenario['body'] ?? [], ['Accept'=>'application/json']);
            if (str_contains($filename, 'deleted')) {
                test()->deleteJson('/api/v1/expenses/'.$fixture['expense']->id, ['reason'=>'Contoh hapus sebelum baca']);
                $response = test()->deleteJson('/api/v1/expenses/'.$fixture['expense']->id);
            }
            $payload = $response->getContent() === '' ? null : (json_decode($response->getContent(), true) ?? $response->getContent());
            expect($response->getStatusCode())->toBe($scenario['status'], "Unexpected status for {$filename}");
            $examples[$filename] = [
                'request'=>['method'=>$scenario['method'],'path'=>$scenario['path'],'query'=>$query,'body'=>exampleSafeValue($scenario['body'] ?? [])],
                'response'=>['status'=>$response->getStatusCode(),'body'=>exampleSafeValue($payload)],
            ];
        } finally {
            DB::rollBack();
            Sanctum::actingAs($fixture['owner']);
        }
    }
    ksort($examples);
    return $examples;
}

afterEach(function (): void {
    Carbon::setTestNow();
});

it('CX-01 through CX-05 generates deterministic sanitized examples for every v1 route', function () {
    $fixture = exampleFixture();
    $first = array_merge(buildRouteExamples($fixture), buildNamedScenarioExamples($fixture));
    ksort($first);

    foreach ($first as $filename => $example) {
        if (preg_match('/__(\d{3})\.json$/', $filename, $match) === 1) {
            expect($example['response']['status'])->toBe((int) $match[1], "Status suffix mismatch: {$filename}");
        }
    }
    expect(array_filter(array_keys($first), static fn (string $name): bool => str_contains($name, 'finalize')))->toBeEmpty();
    expect($first)->toHaveKeys(array_keys(namedExampleScenarios($fixture)));
    expect($first['GET__purchases_purchase__batch_100_55.json']['response']['body']['data'])
        ->toMatchArray(['gross_weight_kg' => '100.000', 'sorted_weight_kg' => '55.000', 'remaining_weight_kg' => '45.000', 'sort_percentage' => 55]);

    $directory = base_path('docs/api-examples');
    if (env('WRITE_EXAMPLES') === '1') {
        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }
        foreach ($first as $filename => $example) {
            file_put_contents($directory.'/'.$filename, json_encode($example, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);
        }
    }

    $files = glob($directory.'/*.json') ?: [];
    expect($files)->not->toBeEmpty();
    foreach ($first as $filename => $example) {
        $path = $directory.'/'.$filename;
        expect(is_file($path))->toBeTrue("Missing route example {$filename}");
        $contents = file_get_contents($path);
        $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        expect($decoded)->toBe($example)
            ->and($contents)->not->toMatch('/(Bearer\s+[A-Za-z0-9._|-]+|password\s*"\s*:\s*"(?!REDACTED)|APP_KEY\s*"\s*:\s*"(?!REDACTED)|"price_3kg"\s*:|"price_tier"\s*:\s*"3kg")/i');
    }

    $finance = json_decode(file_get_contents($directory.'/GET__reports_finance.json'), true, flags: JSON_THROW_ON_ERROR)['response']['body']['data'];
    expect($finance)->toHaveKeys(['cleaning_loss_value', 'net_profit', 'daily'])
        ->and($finance['daily'][0])->toHaveKeys(['cleaning_loss_value', 'net_profit']);
    $stock = json_decode(file_get_contents($directory.'/GET__reports_stock.json'), true, flags: JSON_THROW_ON_ERROR)['response']['body']['data'];
    expect($stock)->toHaveKeys(['from', 'to', 'incoming_gross_kg', 'sorted_kg', 'warehouse_kg', 'display_kg', 'shortfall_kg', 'sold_kg', 'display_shrink_kg', 'display_shrink_value', 'cleaning_shrink_kg', 'cleaning_loss_value', 'susut_kg', 'avg_purchase_price_per_kg', 'susut_value_estimate', 'stock_value', 'products']);
});
