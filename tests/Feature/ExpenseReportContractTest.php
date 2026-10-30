<?php

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['app.debug' => false]);
    Carbon::setTestNow(Carbon::parse('2026-10-27 10:00:00', 'Asia/Jakarta'));
    app(\Database\Seeders\DatabaseSeeder::class)->run();
    Sanctum::actingAs(User::query()->where('username', 'owner')->firstOrFail());
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('RC-01 wraps seeded finance report and returns every WIB day in the month', function () {
    $response = $this->getJson('/api/v1/reports/finance?period=monthly&month=2026-10')->assertOk();
    $report = $response->json('data');

    expect($response->json())->toHaveKey('data')
        ->and($report['daily'])->toHaveCount(31)
        ->and(array_column($report['daily'], 'date'))->toBe(array_map(
            static fn (int $day): string => sprintf('2026-10-%02d', $day),
            range(1, 31)
        ));

    foreach ($report['daily'] as $day) {
        expect($day['date'])->toMatch('/^\d{4}-\d{2}-\d{2}$/');
    }

    expect($report['daily'][1])->toMatchArray([
        'date' => '2026-10-02', 'net_sales' => 0, 'operating_expenses' => 0,
        'purchases_total' => 0, 'cogs' => 0, 'display_shrink_value' => 0, 'net_profit' => 0,
    ]);
});

it('RC-02 keeps seeded finance and stock response examples identical to the live API', function () {
    foreach ([
        'GET__reports_finance.json' => '/api/v1/reports/finance?period=monthly&month=2026-10',
        'GET__reports_stock.json' => '/api/v1/reports/stock?from=2026-10-01&to=2026-10-27',
    ] as $file => $url) {
        $actual = $this->getJson($url)->assertOk()->json();
        $example = json_decode(file_get_contents(base_path('docs/api-examples/'.$file)), true, flags: JSON_THROW_ON_ERROR);
        expect($example['response']['body'])->toBe($actual);
    }
});
