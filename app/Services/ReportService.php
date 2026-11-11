<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\StockCount;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Support\BusinessDay;
use Carbon\Carbon;

final class ReportService
{
    public function __construct(
        private readonly AverageCostService $averageCost,
        private readonly PurchaseService $purchaseService,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, int|string>
     */
    public function finance(array $filters): array
    {
        [$from, $to, $legacyPeriod, $legacyLabel] = $this->financeDates($filters);
        [$start, $end] = BusinessDay::between($from, $to);
        $localEnd = Carbon::createFromFormat('!Y-m-d', $to, 'Asia/Jakarta')->addDay()->toDateString();
        $localStart = $from;
        $dateMap = [];
        $cursor = Carbon::createFromFormat('!Y-m-d', $from, 'Asia/Jakarta');
        $last = Carbon::createFromFormat('!Y-m-d', $to, 'Asia/Jakarta');
        while ($cursor->lte($last)) {
            $dateMap[$cursor->toDateString()] = [
                'date' => $cursor->toDateString(), 'net_sales' => 0, 'operating_expenses' => 0,
                'purchases_total' => 0, 'cogs' => 0, 'display_shrink_value' => 0,
                'cleaning_loss_value' => 0, 'net_profit' => 0,
            ];
            $cursor->addDay();
        }
        $txDate = BusinessDay::localDateExpression('transactions.occurred_at');
        $salesRows = Transaction::query()->selectRaw("{$txDate} as report_date")
            ->selectRaw('COUNT(*) as transactions_count, SUM(subtotal) as subtotal_sum, SUM(discount) as discount_sum, SUM(total) as net_sales')
            ->where('status', 'completed')->where('occurred_at', '>=', $start)->where('occurred_at', '<', $end)
            ->groupBy('report_date')->get()->keyBy('report_date');
        $salesMethodRows = Transaction::query()->select('payment_method')
            ->selectRaw('COUNT(*) as count, SUM(total) as total')->where('status', 'completed')
            ->where('occurred_at', '>=', $start)->where('occurred_at', '<', $end)
            ->groupBy('payment_method')->get()->keyBy('payment_method');
        $cogsRows = TransactionItem::query()->join('transactions', 'transactions.id', '=', 'transaction_items.transaction_id')
            ->selectRaw("{$txDate} as report_date, SUM(transaction_items.cogs) as cogs")
            ->where('transactions.status', 'completed')->where('transactions.occurred_at', '>=', $start)
            ->where('transactions.occurred_at', '<', $end)->groupBy('report_date')->get()->keyBy('report_date');
        $countDate = BusinessDay::localDateExpression('stock_counts.counted_at');
        $shrinkRows = StockCount::query()->selectRaw("{$countDate} as report_date, SUM(loss_value) as display_shrink_value")
            ->where('status', 'active')->where('counted_at', '>=', $start)->where('counted_at', '<', $end)
            ->groupBy('report_date')->get()->keyBy('report_date');
        $expenseRows = Expense::query()->selectRaw('DATE(spent_at) as report_date, SUM(amount) as operating_expenses')
            ->where('spent_at', '>=', $localStart)->where('spent_at', '<', $localEnd)->groupBy('report_date')->get()->keyBy('report_date');
        $purchaseRows = Purchase::query()->selectRaw('DATE(purchased_at) as report_date, SUM(total_cost) as purchases_total')
            ->where('purchased_at', '>=', $localStart)->where('purchased_at', '<', $localEnd)->groupBy('report_date')->get()->keyBy('report_date');
        $cleaningByDate = [];
        foreach ($dateMap as $date => &$row) {
            $sales = $salesRows->get($date);
            $row['net_sales'] = (int) ($sales->net_sales ?? 0);
            $row['cogs'] = (int) ($cogsRows->get($date)->cogs ?? 0);
            $row['display_shrink_value'] = (int) ($shrinkRows->get($date)->display_shrink_value ?? 0);
            $row['cleaning_loss_value'] = (int) ($cleaningByDate[$date] ?? 0);
            $row['operating_expenses'] = (int) ($expenseRows->get($date)->operating_expenses ?? 0);
            $row['purchases_total'] = (int) ($purchaseRows->get($date)->purchases_total ?? 0);
            $row['net_profit'] = $row['net_sales'] - $row['cogs'] - $row['display_shrink_value']
                - $row['cleaning_loss_value'] - $row['operating_expenses'];
        }
        unset($row);
        $daily = array_values($dateMap);
        $sum = static fn (string $key): int => array_sum(array_column($daily, $key));
        $netSales = $sum('net_sales');
        $cogs = $sum('cogs');
        $displayShrinkValue = $sum('display_shrink_value');
        $cleaningLossValue = $sum('cleaning_loss_value');
        $operatingExpenses = $sum('operating_expenses');
        $purchasesTotal = $sum('purchases_total');
        $subtotalSum = (int) $salesRows->sum('subtotal_sum');
        $discountSum = (int) $salesRows->sum('discount_sum');
        $transactionsCount = (int) $salesRows->sum('transactions_count');
        $completedIds = Transaction::query()->where('status', 'completed')->where('occurred_at', '>=', $start)->where('occurred_at', '<', $end)->select('id');
        $shortfallCount = TransactionItem::query()->whereIn('transaction_id', $completedIds)->where('shortfall_kg', '>', 0)->count();
        $voidCount = Transaction::query()->where('status', 'void')->where('occurred_at', '>=', $start)->where('occurred_at', '<', $end)->count();
        $byPayment = ['cash' => ['count' => 0, 'total' => 0], 'qris' => ['count' => 0, 'total' => 0]];
        foreach ($salesMethodRows as $method => $result) {
            if (isset($byPayment[$method])) {
                $byPayment[$method] = ['count' => (int) $result->count, 'total' => (int) $result->total];
            }
        }
        $grossProfit = $netSales - $cogs;
        $totalSpending = $operatingExpenses + $purchasesTotal;

        return [
            'period' => $legacyPeriod, $legacyLabel['key'] => $legacyLabel['value'],
            'from' => $from, 'to' => $to, 'days' => count($daily),
            'transactions_count' => $transactionsCount, 'subtotal_sum' => $subtotalSum, 'discount_sum' => $discountSum,
            'gross_profit' => $grossProfit, 'total_spending' => $totalSpending, 'by_payment_method' => $byPayment, 'daily' => $daily,
            'net_sales' => $netSales, 'cogs' => $cogs, 'display_shrink_value' => $displayShrinkValue,
            'cleaning_loss_value' => $cleaningLossValue,
            'operating_expenses' => $operatingExpenses, 'net_profit' => $sum('net_profit'), 'purchases_total' => $purchasesTotal,
            'cash_flow' => $netSales - $totalSpending,
            'cleaning_shrink_kg' => '0.000',
            'shortfall_count' => (int) $shortfallCount, 'void_count' => (int) $voidCount,
        ];
    }

    /** @return list<array<string, mixed>> */
    public function salesByProduct(array $filters): array
    {
        [$start, $end] = $this->dateBounds((string) $filters['from'], (string) $filters['to']);
        $rows = TransactionItem::query()
            ->select('transaction_items.*', 'products.name as report_product_name')
            ->join('transactions', 'transactions.id', '=', 'transaction_items.transaction_id')
            ->join('products', 'products.id', '=', 'transaction_items.product_id')
            ->where('transactions.status', 'completed')
            ->where('transactions.occurred_at', '>=', $start)
            ->where('transactions.occurred_at', '<', $end)
            ->get();
        $shortfalls = TransactionItem::query()->join('transactions', 'transactions.id', '=', 'transaction_items.transaction_id')
            ->where('transactions.status', 'completed')->where('transactions.occurred_at', '>=', $start)
            ->where('transactions.occurred_at', '<', $end)->where('transaction_items.shortfall_kg', '>', 0)
            ->select('transaction_items.product_id')->selectRaw('COUNT(*) AS total')
            ->groupBy('transaction_items.product_id')->pluck('total', 'transaction_items.product_id');
        $grouped = [];
        foreach ($rows as $row) {
            $id = (int) $row->product_id;
            $grouped[$id] ??= [
                'product_id' => $id,
                'product_name' => $row->report_product_name,
                'sold_weight_kg' => 0,
                'revenue' => 0,
                'cogs' => 0,
                'shortfall_count' => (int) ($shortfalls[$id] ?? 0),
            ];
            $grouped[$id]['sold_weight_kg'] += $this->toMilli((string) $row->weight_kg);
            $grouped[$id]['revenue'] += (int) $row->line_total;
            $grouped[$id]['cogs'] += (int) $row->cogs;
        }

        return array_values(array_map(function (array $row): array {
            $row['sold_weight_kg'] = $this->formatWeight($row['sold_weight_kg']);

            return $row;
        }, $grouped));
    }

    /** @return array<string, mixed> */
    public function stock(array $filters): array
    {
        [$from, $to] = $this->stockDates($filters);
        [$start, $end] = BusinessDay::between($from, $to);
        $localEnd = Carbon::createFromFormat('!Y-m-d', $to, 'Asia/Jakarta')->addDay()->toDateString();
        $purchases = Purchase::query()->with('stockLots.product')->where('purchased_at', '>=', $from)
            ->where('purchased_at', '<', $localEnd)->get();
        $purchases = $this->purchaseService->deriveMetrics($purchases);
        $incoming = $susut = $purchasesCost = $purchaseGross = 0;
        foreach ($purchases as $purchase) {
            $incoming += $this->toMilli((string) $purchase->gross_weight_kg);
            $susut += $this->toMilli((string) $purchase->susut_kg);
            $purchasesCost += (int) $purchase->total_cost;
            $purchaseGross += $this->toMilli((string) $purchase->gross_weight_kg);
        }

        $sortedRaw = StockLot::query()->where('sorted_at', '>=', $from)->where('sorted_at', '<=', $to)
            ->selectRaw('COALESCE(SUM(weight_kg), 0) AS total')->value('total');
        $sorted = $this->toMilli((string) $sortedRaw);
        $warehouse = 0;
        $purchasesWithCounts = Purchase::query()->withSum('stockLots as sorted_kg', 'weight_kg')->get();
        foreach ($purchasesWithCounts as $purchase) {
            $warehouse += max(0, $this->toMilli((string) $purchase->gross_weight_kg)
                - $this->toMilli((string) ($purchase->sorted_kg ?? '0')));
        }

        $cleaningKg = 0;
        $cleaningValue = 0;

        $soldRows = TransactionItem::query()->join('transactions', 'transactions.id', '=', 'transaction_items.transaction_id')
            ->where('transactions.status', 'completed')->where('transactions.occurred_at', '>=', $start)->where('transactions.occurred_at', '<', $end)
            ->select('transaction_items.product_id')->selectRaw('SUM(transaction_items.weight_kg) AS sold_kg')
            ->groupBy('transaction_items.product_id')->get()->keyBy('product_id');
        $countRows = StockCount::query()->where('status', 'active')->where('counted_at', '>=', $start)->where('counted_at', '<', $end)
            ->select('product_id')->selectRaw('SUM(CASE WHEN diff_kg < 0 THEN -diff_kg ELSE 0 END) AS shrink_kg, SUM(loss_value) AS shrink_value')
            ->groupBy('product_id')->get()->keyBy('product_id');
        $lastCounts = StockCount::query()->where('status', 'active')->where('counted_at', '<', $end)
            ->orderByDesc('counted_at')->orderByDesc('id')->get(['product_id', 'counted_at'])->unique('product_id')->keyBy('product_id');
        $balances = StockMovement::query()->select('product_id')
            ->selectRaw('COALESCE(SUM(weight_kg), 0) AS stock_kg, COALESCE(SUM(value_delta), 0) AS stock_value')
            ->groupBy('product_id')->get()->keyBy('product_id');

        $display = $shortfall = $stockValue = $sold = $displayShrink = $displayShrinkValue = 0;
        $productRows = [];
        foreach (Product::query()->orderBy('name')->get() as $product) {
            $balance = $balances->get($product->id);
            $stockMilli = $this->toMilli((string) ($balance->stock_kg ?? '0'));
            $value = (int) ($balance->stock_value ?? 0);
            $productSold = $this->toMilli((string) ($soldRows->get($product->id)->sold_kg ?? '0'));
            $count = $countRows->get($product->id);
            $productShrink = $this->toMilli((string) ($count->shrink_kg ?? '0'));
            $productShrinkValue = (int) ($count->shrink_value ?? 0);
            $lastCount = $lastCounts->get($product->id);
            $display += max(0, $stockMilli);
            $shortfall += max(0, -$stockMilli);
            $stockValue += $value;
            $sold += $productSold;
            $displayShrink += $productShrink;
            $displayShrinkValue += $productShrinkValue;
            $productRows[] = [
                'product_id' => (int) $product->id, 'name' => $product->name, 'jenis' => $product->jenis,
                'is_active' => (bool) $product->is_active, 'stock_kg' => $this->formatWeight($stockMilli),
                'shortfall_kg' => $this->formatWeight(max(0, -$stockMilli)), 'stock_value' => $value,
                'avg_cost' => $stockMilli > 0 ? $this->averageCostFor($stockMilli, $value) : null,
                'sold_kg' => $this->formatWeight($productSold), 'display_shrink_kg' => $this->formatWeight($productShrink),
                'display_shrink_value' => $productShrinkValue,
                'last_count_date' => $lastCount ? Carbon::parse($lastCount->counted_at)->setTimezone('Asia/Jakarta')->toDateString() : null,
            ];
        }
        $averagePurchasePrice = $purchaseGross > 0 ? $this->divideHalfUp($purchasesCost * 1000, $purchaseGross) : null;

        return [
            'from' => $from, 'to' => $to, 'incoming_gross_kg' => $this->formatWeight($incoming),
            'sorted_kg' => $this->formatWeight($sorted), 'warehouse_kg' => $this->formatWeight($warehouse),
            'display_kg' => $this->formatWeight($display), 'shortfall_kg' => $this->formatWeight($shortfall),
            'sold_kg' => $this->formatWeight($sold), 'display_shrink_kg' => $this->formatWeight($displayShrink),
            'display_shrink_value' => $displayShrinkValue, 'cleaning_shrink_kg' => $this->formatWeight($cleaningKg),
            'cleaning_loss_value' => $cleaningValue, 'susut_kg' => $this->formatWeight($susut),
            'avg_purchase_price_per_kg' => $averagePurchasePrice,
            'susut_value_estimate' => $averagePurchasePrice === null ? null : $this->valueForWeight($susut, $averagePurchasePrice),
            'stock_value' => $stockValue, 'products' => $productRows,
        ];
    }

    /** @return list<array<string, mixed>> */
    public function shifts(array $filters): array
    {
        [$start, $end] = $this->dateBounds((string) $filters['date'], (string) $filters['date']);

        return Transaction::query()
            ->select('cashier_id')
            ->selectRaw('sum(case when status = ? then 1 else 0 end) as transaction_count', ['completed'])
            ->selectRaw('sum(case when status = ? then total else 0 end) as revenue', ['completed'])
            ->selectRaw('sum(case when status = ? then 1 else 0 end) as void_count', ['void'])
            ->with('cashier:id,name')
            ->where('occurred_at', '>=', $start)
            ->where('occurred_at', '<', $end)
            ->groupBy('cashier_id')
            ->orderBy('cashier_id')
            ->get()
            ->map(fn (Transaction $row): array => [
                'cashier_id' => $row->cashier_id,
                'cashier_name' => $row->cashier?->name,
                'transaction_count' => (int) $row->transaction_count,
                'revenue' => (int) $row->revenue,
                'void_count' => (int) $row->void_count,
            ])->all();
    }

    /** @return array{0: string, 1: string, 2: string, 3: array{key: string, value: string}} */
    private function financeDates(array $filters): array
    {
        if (isset($filters['from'], $filters['to'])) {
            return [(string) $filters['from'], (string) $filters['to'], 'range', ['key' => 'from', 'value' => (string) $filters['from']]];
        }
        $period = $filters['period'] ?? 'monthly';
        if ($period === 'daily') {
            $date = (string) $filters['date'];

            return [$date, $date, 'daily', ['key' => 'date', 'value' => $date]];
        }
        $month = (string) ($filters['month'] ?? Carbon::now('Asia/Jakarta')->format('Y-m'));
        $start = Carbon::createFromFormat('!Y-m', $month, 'Asia/Jakarta')->startOfMonth();

        return [$start->toDateString(), $start->copy()->endOfMonth()->toDateString(), 'monthly', ['key' => 'month', 'value' => $month]];
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function dateBounds(string $from, string $to): array
    {
        return BusinessDay::between($from, $to);
    }

    /** @return array{0: string, 1: string} */
    private function stockDates(array $filters): array
    {
        if (isset($filters['from'], $filters['to'])) {
            return [(string) $filters['from'], (string) $filters['to']];
        }
        if (isset($filters['date'])) {
            return [(string) $filters['date'], (string) $filters['date']];
        }
        $now = Carbon::now('Asia/Jakarta');

        return [$now->copy()->startOfMonth()->toDateString(), $now->toDateString()];
    }

    private function toMilli(string $weight): int
    {
        $negative = str_starts_with(trim($weight), '-');
        $normalized = ltrim(trim($weight), '+-');
        [$whole, $fraction] = array_pad(explode('.', $normalized, 2), 2, '');
        $value = ((int) $whole * 1000) + (int) str_pad(substr($fraction, 0, 3), 3, '0');

        return $negative ? -$value : $value;
    }

    private function formatWeight(int $milli): string
    {
        $sign = $milli < 0 ? '-' : '';
        $absolute = abs($milli);

        return $sign.intdiv($absolute, 1000).'.'.str_pad((string) ($absolute % 1000), 3, '0', STR_PAD_LEFT);
    }

    private function valueForWeight(int $weightMilli, int $pricePerKg): int
    {
        $numerator = bcmul((string) $weightMilli, (string) $pricePerKg, 0);
        $quotient = (int) bcdiv($numerator, '1000', 0);
        $remainder = (int) bcmod($numerator, '1000');

        return $quotient + ($remainder * 2 >= 1000 ? 1 : 0);
    }

    private function divideHalfUp(int $numerator, int $denominator): int
    {
        $quotient = intdiv($numerator, $denominator);
        $remainder = $numerator % $denominator;

        return $quotient + ($remainder * 2 >= $denominator ? 1 : 0);
    }

    private function averageCostFor(int $stockMilli, int $stockValue): int
    {
        $numerator = bcmul((string) $stockValue, '1000', 0);
        $negative = str_starts_with($numerator, '-');
        $absolute = ltrim($numerator, '-');
        $quotient = (int) bcdiv($absolute, (string) $stockMilli, 0);
        $remainder = (int) bcmod($absolute, (string) $stockMilli);
        $result = $quotient + ($remainder * 2 >= $stockMilli ? 1 : 0);

        return $negative ? -$result : $result;
    }
}
