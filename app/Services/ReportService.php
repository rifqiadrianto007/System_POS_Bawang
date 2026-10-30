<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\StockCount;
use App\Models\StockMovement;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Support\BusinessDay;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

final class ReportService
{
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
                'purchases_total' => 0, 'cogs' => 0, 'display_shrink_value' => 0, 'net_profit' => 0,
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
            ->where('counted_at', '>=', $start)->where('counted_at', '<', $end)->groupBy('report_date')->get()->keyBy('report_date');
        $expenseRows = Expense::query()->selectRaw('DATE(spent_at) as report_date, SUM(amount) as operating_expenses')
            ->where('spent_at', '>=', $localStart)->where('spent_at', '<', $localEnd)->groupBy('report_date')->get()->keyBy('report_date');
        $purchaseRows = Purchase::query()->selectRaw('DATE(purchased_at) as report_date, SUM(total_cost) as purchases_total')
            ->where('purchased_at', '>=', $localStart)->where('purchased_at', '<', $localEnd)->groupBy('report_date')->get()->keyBy('report_date');
        $finalizedRows = Purchase::query()->where('status', 'finalized')->where('purchased_at', '>=', $localStart)
            ->where('purchased_at', '<', $localEnd)->get(['purchased_at', 'gross_weight_kg', 'clean_weight_kg']);
        $cleaningByDate = [];
        $cleaningTotal = 0;
        foreach ($finalizedRows as $purchase) {
            $date = Carbon::parse($purchase->purchased_at)->toDateString();
            $kg = $this->toMilli((string) $purchase->gross_weight_kg) - $this->toMilli((string) $purchase->clean_weight_kg);
            $cleaningByDate[$date] = ($cleaningByDate[$date] ?? 0) + $kg;
            $cleaningTotal += $kg;
        }
        foreach ($dateMap as $date => &$row) {
            $sales = $salesRows->get($date);
            $row['net_sales'] = (int) ($sales->net_sales ?? 0);
            $row['cogs'] = (int) ($cogsRows->get($date)->cogs ?? 0);
            $row['display_shrink_value'] = (int) ($shrinkRows->get($date)->display_shrink_value ?? 0);
            $row['operating_expenses'] = (int) ($expenseRows->get($date)->operating_expenses ?? 0);
            $row['purchases_total'] = (int) ($purchaseRows->get($date)->purchases_total ?? 0);
            $row['net_profit'] = $row['net_sales'] - $row['cogs'] - $row['display_shrink_value'] - $row['operating_expenses'];
        }
        unset($row);
        $daily = array_values($dateMap);
        $sum = static fn (string $key): int => array_sum(array_column($daily, $key));
        $netSales = $sum('net_sales');
        $cogs = $sum('cogs');
        $displayShrinkValue = $sum('display_shrink_value');
        $operatingExpenses = $sum('operating_expenses');
        $purchasesTotal = $sum('purchases_total');
        $subtotalSum = (int) $salesRows->sum('subtotal_sum');
        $discountSum = (int) $salesRows->sum('discount_sum');
        $transactionsCount = (int) $salesRows->sum('transactions_count');
        $completedIds = Transaction::query()->where('status', 'completed')->where('occurred_at', '>=', $start)->where('occurred_at', '<', $end)->select('id');
        $shortfallCount = DB::table('sale_allocations')->join('transaction_items', 'transaction_items.id', '=', 'sale_allocations.transaction_item_id')
            ->whereIn('transaction_items.transaction_id', $completedIds)->where('sale_allocations.is_shortfall', true)->count();
        $voidCount = Transaction::query()->where('status', 'void')->where('occurred_at', '>=', $start)->where('occurred_at', '<', $end)->count();
        $byPayment = ['cash' => ['count' => 0, 'total' => 0], 'qris' => ['count' => 0, 'total' => 0]];
        foreach ($salesMethodRows as $method => $result) {
            if (isset($byPayment[$method])) $byPayment[$method] = ['count' => (int) $result->count, 'total' => (int) $result->total];
        }
        $grossProfit = $netSales - $cogs;
        $totalSpending = $operatingExpenses + $purchasesTotal;

        return [
            'period' => $legacyPeriod, $legacyLabel['key'] => $legacyLabel['value'],
            'from' => $from, 'to' => $to, 'days' => count($daily),
            'transactions_count' => $transactionsCount, 'subtotal_sum' => $subtotalSum, 'discount_sum' => $discountSum,
            'gross_profit' => $grossProfit, 'total_spending' => $totalSpending, 'by_payment_method' => $byPayment, 'daily' => $daily,
            'net_sales' => $netSales, 'cogs' => $cogs, 'display_shrink_value' => $displayShrinkValue,
            'operating_expenses' => $operatingExpenses, 'net_profit' => $sum('net_profit'), 'purchases_total' => $purchasesTotal,
            'cash_flow' => $netSales - $totalSpending, 'cleaning_shrink_kg' => $this->formatWeight($cleaningTotal),
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
        $shortfalls = DB::table('sale_allocations')
            ->join('transaction_items', 'transaction_items.id', '=', 'sale_allocations.transaction_item_id')
            ->join('transactions', 'transactions.id', '=', 'transaction_items.transaction_id')
            ->where('transactions.status', 'completed')
            ->where('transactions.occurred_at', '>=', $start)
            ->where('transactions.occurred_at', '<', $end)
            ->where('sale_allocations.is_shortfall', true)
            ->select('transaction_items.product_id', DB::raw('count(*) as total'))
            ->groupBy('transaction_items.product_id')
            ->pluck('total', 'transaction_items.product_id');
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
        $purchases = Purchase::query()->where('purchased_at', '>=', $from)->where('purchased_at', '<', $localEnd)->get();
        $movements = StockMovement::query()->where('occurred_at', '>=', $start)->where('occurred_at', '<', $end)->get();
        $counts = StockCount::query()->where('counted_at', '>=', $start)->where('counted_at', '<', $end)->get();
        $soldRows = TransactionItem::query()->join('transactions', 'transactions.id', '=', 'transaction_items.transaction_id')
            ->select('transaction_items.product_id')->selectRaw('SUM(transaction_items.weight_kg) as sold')
            ->where('transactions.status', 'completed')->where('transactions.occurred_at', '>=', $start)
            ->where('transactions.occurred_at', '<', $end)->groupBy('transaction_items.product_id')->get()->keyBy('product_id');
        $lastCounts = StockCount::query()->where('counted_at', '<', $end)->orderByDesc('counted_at')->get(['product_id', 'counted_at'])
            ->unique('product_id')->keyBy('product_id');
        $products = Product::query()->with(['stockMovements', 'stockLots.movements'])->orderBy('name')->get();
        $incoming = $pending = $clean = $cleaningShrink = 0;
        foreach ($purchases as $purchase) {
            $gross = $this->toMilli((string) $purchase->gross_weight_kg);
            $incoming += $gross;
            if ($purchase->status === 'open') {
                $pending += $gross;
            } elseif ($purchase->status === 'finalized') {
                $clean += $this->toMilli((string) $purchase->clean_weight_kg);
                $cleaningShrink += $gross - $this->toMilli((string) $purchase->clean_weight_kg);
            }
        }
        $displayShrink = $displayShrinkValue = $stockReady = $stockValue = 0;
        foreach ($counts as $count) {
            $displayShrink += $this->toMilli((string) $count->diff_kg);
            $displayShrinkValue += (int) $count->loss_value;
        }
        $productRows = [];
        $legacyReceived = $legacySold = $soldTotal = 0;
        foreach ($products as $product) {
            $stock = $this->sumMovementWeights($product->stockMovements);
            $stockReady += $stock;
            $value = 0;
            foreach ($product->stockLots as $lot) {
                $remaining = $this->sumMovementWeights($lot->movements);
                if ($remaining > 0) {
                    $value += intdiv(($remaining * (int) $lot->unit_cost) + 500, 1000);
                }
            }
            $stockValue += $value;
            $productSold = $soldRows->get($product->id);
            $soldMilli = $productSold ? $this->toMilli((string) $productSold->sold) : 0;
            $soldTotal += $soldMilli;
            $productCounts = $counts->where('product_id', $product->id);
            $productShrink = $productShrinkValue = 0;
            $lastCountDate = null;
            foreach ($productCounts as $count) {
                $productShrink += $this->toMilli((string) $count->diff_kg);
                $productShrinkValue += (int) $count->loss_value;
            }
            $lastCount = $lastCounts->get($product->id);
            if ($lastCount) $lastCountDate = Carbon::parse($lastCount->counted_at)->setTimezone('Asia/Jakarta')->toDateString();
            $received = 0;
            foreach ($movements->where('product_id', $product->id) as $movement) {
                if ($movement->type === 'in_clean' && $this->toMilli((string) $movement->weight_kg) > 0) $received += $this->toMilli((string) $movement->weight_kg);
            }
            $legacyReceived += $received;
            $legacyProductSold = 0;
            foreach ($movements->where('product_id', $product->id) as $movement) {
                if ($movement->type === 'sale' && $this->toMilli((string) $movement->weight_kg) < 0) {
                    $legacyProductSold += abs($this->toMilli((string) $movement->weight_kg));
                }
            }
            $legacySold += $legacyProductSold;
            $productRows[] = [
                'product_id' => (int) $product->id, 'name' => $product->name, 'is_active' => (bool) $product->is_active,
                'stock_kg' => $this->formatWeight($stock), 'stock_value' => (int) $value,
                'sold_kg' => $this->formatWeight($soldMilli), 'display_shrink_kg' => $this->formatWeight($productShrink),
                'display_shrink_value' => (int) $productShrinkValue, 'last_count_date' => $lastCountDate,
                // Kept for existing report clients while the contract is extended.
                'product_name' => $product->name, 'received_weight_kg' => $this->formatWeight($received),
                'stock_weight_kg' => $this->formatWeight($stock), 'sold_weight_kg' => $this->formatWeight($legacyProductSold),
            ];
        }
        $legacySummary = [
            'received_weight_kg' => $this->formatWeight($legacyReceived), 'stock_weight_kg' => $this->formatWeight($stockReady),
            'sold_weight_kg' => $this->formatWeight($legacySold), 'cleaning_shrink_kg' => $this->formatWeight($cleaningShrink),
            'display_shrink_kg' => $this->formatWeight(max(0, -$displayShrink)), 'display_shrink_value' => (int) $displayShrinkValue,
        ];
        return [
            'from' => $from, 'to' => $to, 'incoming_gross_kg' => $this->formatWeight($incoming),
            'pending_gross_kg' => $this->formatWeight($pending), 'clean_kg' => $this->formatWeight($clean),
            'cleaning_shrink_kg' => $this->formatWeight($cleaningShrink), 'sold_kg' => $this->formatWeight($soldTotal),
            'display_shrink_kg' => $this->formatWeight($displayShrink), 'display_shrink_value' => (int) $displayShrinkValue,
            'stock_ready_kg' => $this->formatWeight($stockReady), 'stock_value' => (int) $stockValue,
            'products' => $productRows, 'date' => $from, 'summary' => $legacySummary,
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

    private function sumMovementWeights(iterable $movements): int
    {
        $total = 0;
        foreach ($movements as $movement) {
            $total += $this->toMilli((string) $movement->weight_kg);
        }

        return $total;
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
}
