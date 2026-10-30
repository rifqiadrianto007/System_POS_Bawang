<?php

use App\Models\Expense;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\StockLot;
use App\Models\Supplier;
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
    app(\Database\Seeders\DatabaseSeeder::class)->run();
    $owner = User::query()->where('username', 'owner')->firstOrFail();
    Sanctum::actingAs($owner);

    $product = Product::query()->where('name', 'Merah A')->firstOrFail();
    $supplier = Supplier::query()->firstOrFail();
    $finalPurchase = Purchase::query()->where('status', 'finalized')->firstOrFail();
    $openPurchases = Purchase::query()->where('status', 'open')->orderBy('id')->get();
    $expense = Expense::query()->firstOrFail();
    $transaction = Transaction::query()->where('status', 'completed')->orderBy('id')->firstOrFail();

    return [
        'owner' => $owner,
        'product' => $product,
        'supplier' => $supplier,
        'final_purchase' => $finalPurchase,
        'open_purchase' => $openPurchases[0],
        'lot_purchase' => $openPurchases[1],
        'lot' => $openPurchases[1]->stockLots()->firstOrFail(),
        'expense' => $expense,
        'transaction' => $transaction,
    ];
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
            'name' => 'Produk Contoh Baru', 'price_retail' => 27000, 'price_3kg' => 25000, 'price_5kg' => 23000,
        ],
        preg_match('#^products/\{product\}$#', $path) === 1 && $method === 'PUT' => [
            'name' => 'Merah A', 'price_retail' => 28000, 'price_3kg' => 26000, 'price_5kg' => 24000,
        ],
        preg_match('#^products/\{product\}/active$#', $path) === 1 => ['is_active' => true],
        $path === 'suppliers' && $method === 'POST' => ['name' => 'Pemasok Contoh Baru', 'phone' => '081200000001', 'note' => 'Contoh API'],
        preg_match('#^suppliers/\{supplier\}$#', $path) === 1 && $method === 'PUT' => ['name' => $supplier->name],
        $path === 'purchases' && $method === 'POST' => [
            'supplier_id' => $supplier->id, 'purchased_at' => '2026-10-27', 'gross_weight_kg' => '10.000', 'price_per_kg' => 17000,
            'notes' => 'Contoh kulak API',
        ],
        preg_match('#^purchases/\{purchase\}$#', $path) === 1 && $method === 'PUT' => [
            'supplier_id' => $supplier->id, 'purchased_at' => '2026-10-26', 'gross_weight_kg' => '20.000', 'price_per_kg' => 15000,
            'notes' => 'Contoh perubahan',
        ],
        preg_match('#^purchases/\{purchase\}/lots$#', $path) === 1 && $method === 'POST' => [
            'product_id' => $product->id, 'clean_weight_kg' => '1.000', 'cleaned_at' => '2026-10-26', 'notes' => 'Lot contoh',
        ],
        preg_match('#^purchases/\{purchase\}/lots/\{lot\}$#', $path) === 1 && $method === 'PUT' => [
            'product_id' => Product::query()->where('name', 'Merah C')->value('id'),
            'clean_weight_kg' => '4.000', 'cleaned_at' => '2026-10-26', 'notes' => 'Lot diperbarui',
        ],
        $path === 'stock-counts' && $method === 'POST' => [
            'product_id' => $product->id, 'counted_weight_kg' => '0.000', 'counted_at' => '2026-10-27 10:00:00', 'notes' => 'Contoh hitung ulang',
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
    $purchase = str_contains($path, '/lots') || str_ends_with($path, '/finalize')
        ? $fixture['lot_purchase']
        : (($method === 'DELETE' || $method === 'PUT') && preg_match('#^purchases/\{purchase\}$#', $path) === 1
            ? $fixture['open_purchase'] : $fixture['final_purchase']);

    return [
        'product' => $fixture['product']->id,
        'supplier' => $fixture['supplier']->id,
        'purchase' => $purchase->id,
        'lot' => $fixture['lot']->id,
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
                    $invalidKey = $method.'__'.str_replace('/', '_', str_replace(['{', '}'], '', $relativePath)).'__422.json';
                    $examples[$invalidKey] = [
                        'request' => ['method' => $method, 'path' => $actualPath, 'query' => $query, 'body' => []],
                        'response' => ['status' => $invalid->getStatusCode(), 'body' => exampleSafeValue($invalidBody)],
                    ];
                } finally {
                    DB::rollBack();
                }
            }
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
    $first = buildRouteExamples($fixture);
    $second = buildRouteExamples($fixture);
    foreach ($first as $filename => $example) {
        expect($second[$filename])->toBe($example, "Nondeterministic example: {$filename}");
    }

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
        expect($decoded)->toBe($example)->and($contents)->not->toMatch('/(Bearer\s+[A-Za-z0-9._|-]+|password\s*"\s*:\s*"(?!REDACTED)|APP_KEY\s*"\s*:\s*"(?!REDACTED))/i');
    }

    $finance = json_decode(file_get_contents($directory.'/GET__reports_finance.json'), true, flags: JSON_THROW_ON_ERROR)['response']['body']['data'];
    expect($finance)->toHaveKeys(['from', 'to', 'days', 'transactions_count', 'subtotal_sum', 'discount_sum', 'gross_profit', 'total_spending', 'by_payment_method', 'daily'])
        ->and($finance['daily'][0])->toHaveKeys(['date', 'net_sales', 'operating_expenses', 'purchases_total', 'cogs', 'display_shrink_value', 'net_profit']);
    $stock = json_decode(file_get_contents($directory.'/GET__reports_stock.json'), true, flags: JSON_THROW_ON_ERROR)['response']['body']['data'];
    expect($stock)->toHaveKeys(['from', 'to', 'incoming_gross_kg', 'pending_gross_kg', 'clean_kg', 'cleaning_shrink_kg', 'sold_kg', 'display_shrink_kg', 'display_shrink_value', 'stock_ready_kg', 'stock_value', 'products']);
});
