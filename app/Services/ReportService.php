<?php

namespace App\Services;

use App\Models\Expense;
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
    public function __construct(
        private readonly StockService $stockService,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, int|string>
     */
    public function finance(array $filters): array
    {
        [$from, $to, $legacyPeriod, $legacyLabel] = $this->financeDates($filters);
        [$start, $end] = BusinessDay::between($from, $to);
        $localStart = $from;
        $localEnd = Carbon::createFromFormat('!Y-m-d', $to, 'Asia/Jakarta')->addDay()->toDateString();
        $dateMap = [];
        $cursor = Carbon::createFromFormat('!Y-m-d', $from, 'Asia/Jakarta');
        $last = Carbon::createFromFormat('!Y-m-d', $to, 'Asia/Jakarta');
        while ($cursor->lte($last)) {
            $dateMap[$cursor->toDateString()] = [
                'date' => $cursor->toDateString(), 'net_sales' => 0, 'operating_expenses' => 0,
                'purchases_total' => 0, 'cogs' => 0, 'display_shrink_value' => 0,
                'cleaning_loss_value' => 0, 'shrink_value' => 0, 'surplus_value' => 0, 'gross_profit' => 0, 'net_profit' => 0,
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
        $shrinkRows = StockCount::query()->selectRaw("{$countDate} as report_date, SUM(CASE WHEN loss_value > 0 THEN loss_value ELSE 0 END) as display_shrink_value")
            ->where('status', 'active')->where('counted_at', '>=', $start)->where('counted_at', '<', $end)
            ->groupBy('report_date')->get()->keyBy('report_date');
        $surplusRows = StockCount::query()->selectRaw("{$countDate} as report_date, SUM(CASE WHEN surplus_value > 0 THEN surplus_value ELSE 0 END) as surplus_value")
            ->where('status', 'active')->where('counted_at', '>=', $start)->where('counted_at', '<', $end)
            ->groupBy('report_date')->get()->keyBy('report_date');
        $cleanDate = BusinessDay::localDateExpression('purchases.sorted_finished_at');
        $cleaningRows = Purchase::query()->selectRaw("{$cleanDate} as report_date, SUM(cleaning_loss_value) as cleaning_loss_value")
            ->whereNotNull('sorted_finished_at')->where('sorted_finished_at', '>=', $start)->where('sorted_finished_at', '<', $end)
            ->groupBy('report_date')->get()->keyBy('report_date');
        $expenseRows = Expense::query()->selectRaw('DATE(spent_at) as report_date, SUM(amount) as operating_expenses')
            ->where('spent_at', '>=', $from)->where('spent_at', '<', $localEnd)->groupBy('report_date')->get()->keyBy('report_date');
        $purchaseRows = Purchase::query()->selectRaw('DATE(purchased_at) as report_date, SUM(total_cost) as purchases_total')
            ->where('purchased_at', '>=', $localStart)->where('purchased_at', '<', $localEnd)->groupBy('report_date')->get()->keyBy('report_date');
        foreach ($dateMap as $date => &$row) {
            $sales = $salesRows->get($date);
            $row['net_sales'] = (int) ($sales->net_sales ?? 0);
            $row['cogs'] = (int) ($cogsRows->get($date)->cogs ?? 0);
            $row['display_shrink_value'] = (int) ($shrinkRows->get($date)->display_shrink_value ?? 0);
            $row['cleaning_loss_value'] = (int) ($cleaningRows->get($date)->cleaning_loss_value ?? 0);
            $row['surplus_value'] = (int) ($surplusRows->get($date)->surplus_value ?? 0);
            $row['operating_expenses'] = (int) ($expenseRows->get($date)->operating_expenses ?? 0);
            $row['purchases_total'] = (int) ($purchaseRows->get($date)->purchases_total ?? 0);
            $row['gross_profit'] = $row['net_sales'] - $row['cogs'];
            $row['shrink_value'] = $row['display_shrink_value'] + $row['cleaning_loss_value'];
            $row['net_profit'] = $row['gross_profit'] - $row['shrink_value'] - $row['operating_expenses'];
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
        $surplusValue = $sum('surplus_value');
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
        $displayShrinkKg = (int) StockCount::query()->where('status', 'active')->where('diff_kg', '<', 0)
            ->where('counted_at', '>=', $start)->where('counted_at', '<', $end)->get(['diff_kg'])
            ->sum(fn (StockCount $count): int => $this->toMilli((string) $count->diff_kg) * -1);
        $cleaningShrinkKg = (int) Purchase::query()->whereNotNull('sorted_finished_at')->where('sorted_finished_at', '>=', $start)
            ->where('sorted_finished_at', '<', $end)->get(['cleaning_shrink_kg'])
            ->sum(fn (Purchase $purchase): int => $this->toMilli((string) $purchase->cleaning_shrink_kg));
        $totalShrinkValue = $displayShrinkValue + $cleaningLossValue;
        $openingInventory = $this->openingInventoryValue($from);
        $snapshotAsOf = min($to, Carbon::now('Asia/Jakarta')->toDateString());
        $this->ensureSnapshotsThrough($snapshotAsOf);
        $ending = $this->inventoryAt($to);
        $reportMonthEnd = Carbon::createFromFormat('!Y-m-d', $to, 'Asia/Jakarta')->endOfMonth()->toDateString();
        if ($to === $reportMonthEnd || $to === Carbon::now('Asia/Jakarta')->toDateString()) {
            $this->storeMonthlySnapshot($snapshotAsOf, $this->inventoryAt($snapshotAsOf));
        }
        $reconciliationDifference = $purchasesTotal + $openingInventory - $cogs - $totalShrinkValue
            - (int) $ending['inventory_value'] - $surplusValue;
        $totalSpending = $operatingExpenses + $purchasesTotal;

        return [
            'period' => $legacyPeriod, $legacyLabel['key'] => $legacyLabel['value'],
            'from' => $from, 'to' => $to, 'days' => count($daily),
            'transactions_count' => $transactionsCount, 'subtotal_sum' => $subtotalSum, 'discount_sum' => $discountSum,
            'gross_profit' => $grossProfit, 'total_spending' => $totalSpending, 'by_payment_method' => $byPayment, 'daily' => $daily,
            'net_sales' => $netSales, 'cogs' => $cogs, 'display_shrink_value' => $displayShrinkValue,
            'cleaning_loss_value' => $cleaningLossValue, 'shrink_value' => $totalShrinkValue,
            'display_shrink_kg' => $this->formatWeight($displayShrinkKg), 'cleaning_shrink_kg' => $this->formatWeight($cleaningShrinkKg),
            'surplus_value' => $surplusValue,
            'operating_expenses' => $operatingExpenses, 'net_profit' => $sum('net_profit'), 'purchases_total' => $purchasesTotal,
            'opening_inventory_value' => $openingInventory, 'ending_inventory_value' => (int) $ending['inventory_value'],
            'inventory_value' => (int) $ending['inventory_value'],
            'reconciliation_difference' => $reconciliationDifference, 'reconciliation_mismatch' => $reconciliationDifference !== 0,
            'cash_flow' => $netSales - $totalSpending,
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
        $incoming = $this->toMilli((string) Purchase::query()->where('purchased_at', '>=', $from)
            ->where('purchased_at', '<', $localEnd)->sum('gross_weight_kg'));
        $cleanMilli = $this->toMilli((string) DB::table('stock_additions')->whereBetween('added_at', [$from, $to])->sum('weight_kg'));
        $cleaningRows = Purchase::query()->whereNotNull('sorted_finished_at')->where('sorted_finished_at', '>=', $start)
            ->where('sorted_finished_at', '<', $end)->get(['cleaning_shrink_kg', 'cleaning_loss_value']);
        $cleaningMilli = $cleaningRows->sum(fn (Purchase $purchase): int => $this->toMilli((string) $purchase->cleaning_shrink_kg));
        $cleaningValue = (int) $cleaningRows->sum('cleaning_loss_value');
        $summary = $this->stockService->summaries($from, $to);
        $pendingMilli = $this->toMilli($summary['totals']['unsorted_kg']);
        $pendingValue = (int) $summary['totals']['unsorted_value'];
        $displayShrinkMilli = StockCount::query()->where('status', 'active')->where('diff_kg', '<', 0)
            ->where('counted_at', '>=', $start)->where('counted_at', '<', $end)->get(['diff_kg'])
            ->sum(fn (StockCount $count): int => -$this->toMilli((string) $count->diff_kg));
        return [
            'from' => $from, 'to' => $to,
            'incoming_gross_kg' => $this->formatWeight($incoming),
            'pending_gross_kg' => $this->formatWeight($pendingMilli),
            'clean_kg' => $this->formatWeight($cleanMilli),
            'cleaning_shrink_kg' => $this->formatWeight($cleaningMilli), 'cleaning_shrink_value' => $cleaningValue,
            'sold_kg' => $summary['totals']['sold_kg'],
            'display_shrink_kg' => $this->formatWeight($displayShrinkMilli),
            'display_shrink_value' => (int) StockCount::query()->where('status', 'active')->where('counted_at', '>=', $start)
                ->where('counted_at', '<', $end)->where('loss_value', '>', 0)->sum('loss_value'),
            'shrink_kg' => $summary['totals']['shrink_kg'], 'shrink_value' => $summary['totals']['shrink_value'],
            'stock_ready_kg' => $summary['totals']['display_kg'], 'display_value' => $summary['totals']['display_value'],
            'unsorted_value' => $pendingValue, 'stock_value' => (int) $summary['totals']['inventory_value'],
            'products' => $summary['products'],
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

    /** @return array{display_kg:string,display_value:int,unsorted_kg:string,unsorted_value:int,inventory_value:int} */
    private function inventoryAt(string $date): array
    {
        [, $end] = BusinessDay::between($date, $date);
        $nextDate = Carbon::createFromFormat('!Y-m-d', $date, 'Asia/Jakarta')->addDay()->toDateString();
        $display = StockMovement::query()->where('occurred_at', '<', $end)
            ->selectRaw('COALESCE(SUM(weight_kg),0) AS kg, COALESCE(SUM(value_delta),0) AS value')->first();
        $purchases = Purchase::query()->where('purchased_at', '<', $nextDate)
            ->where(fn ($query) => $query->whereNull('sorted_finished_at')->orWhere('sorted_finished_at', '>=', $end))
            ->get(['id', 'gross_weight_kg', 'price_per_kg']);
        $additions = DB::table('stock_additions')->whereIn('purchase_id', $purchases->pluck('id'))->where('added_at', '<', $nextDate)
            ->select('purchase_id')->selectRaw('SUM(weight_kg) AS kg')->groupBy('purchase_id')->pluck('kg', 'purchase_id');
        $unsortedMilli = $unsortedValue = 0;
        foreach ($purchases as $purchase) {
            $addedKg = $additions[$purchase->id] ?? '0';
            $remaining = max(0, $this->toMilli((string) $purchase->gross_weight_kg) - $this->toMilli((string) $addedKg));
            $unsortedMilli += $remaining;
            $unsortedValue += $this->valueForWeight($remaining, (int) $purchase->price_per_kg);
        }
        $displayKg = $this->toMilli((string) ($display->kg ?? '0'));
        $displayValue = (int) ($display->value ?? 0);

        return [
            'display_kg' => $this->formatWeight($displayKg), 'display_value' => $displayValue,
            'unsorted_kg' => $this->formatWeight($unsortedMilli), 'unsorted_value' => $unsortedValue,
            'inventory_value' => $displayValue + $unsortedValue,
        ];
    }

    private function openingInventoryValue(string $from): int
    {
        $date = Carbon::createFromFormat('!Y-m-d', $from, 'Asia/Jakarta');
        $previousDay = $date->copy()->subDay()->toDateString();
        if ($date->day === 1) {
            $this->ensureSnapshotsThrough($previousDay);
            $monthStart = $date->copy()->subMonthNoOverflow()->startOfMonth()->toDateString();
            $snapshot = DB::table('monthly_inventory_snapshots')->where('month_start', $monthStart)->first();
            if ($snapshot !== null) {
                return (int) $snapshot->inventory_value;
            }
        }

        return (int) $this->inventoryAt($previousDay)['inventory_value'];
    }

    private function ensureSnapshotsThrough(string $targetDate): void
    {
        $firstPurchase = Purchase::query()->min('purchased_at');
        $firstMovement = StockMovement::query()->min('occurred_at');
        $firstDate = $firstPurchase ? Carbon::parse($firstPurchase, 'Asia/Jakarta')->toDateString() : null;
        if ($firstMovement) {
            $movementDate = Carbon::parse($firstMovement, 'UTC')->setTimezone('Asia/Jakarta')->toDateString();
            $firstDate = $firstDate === null || $movementDate < $firstDate ? $movementDate : $firstDate;
        }
        if ($firstDate === null || $firstDate > $targetDate) {
            $firstDate = $targetDate;
        }

        $month = Carbon::createFromFormat('!Y-m-d', $firstDate, 'Asia/Jakarta')->startOfMonth();
        $targetMonth = Carbon::createFromFormat('!Y-m-d', $targetDate, 'Asia/Jakarta')->startOfMonth();
        while ($month->lte($targetMonth)) {
            $monthStart = $month->toDateString();
            $monthEnd = $month->copy()->endOfMonth()->toDateString();
            $asOf = $month->equalTo($targetMonth) ? $targetDate : $monthEnd;
            $existing = DB::table('monthly_inventory_snapshots')->where('month_start', $monthStart)->first();
            if ($existing === null || $existing->as_of_date < $asOf) {
                $this->storeMonthlySnapshot($asOf, $this->inventoryAt($asOf));
            }
            $month->addMonthNoOverflow();
        }
    }

    /** @param array{display_kg:string,display_value:int,unsorted_kg:string,unsorted_value:int,inventory_value:int} $inventory */
    private function storeMonthlySnapshot(string $date, array $inventory): void
    {
        $monthStart = Carbon::createFromFormat('!Y-m-d', $date, 'Asia/Jakarta')->startOfMonth()->toDateString();
        $now = now();
        DB::table('monthly_inventory_snapshots')->updateOrInsert(['month_start' => $monthStart], [
            'as_of_date' => $date, 'display_kg' => $inventory['display_kg'], 'display_value' => $inventory['display_value'],
            'unsorted_kg' => $inventory['unsorted_kg'], 'unsorted_value' => $inventory['unsorted_value'],
            'inventory_value' => $inventory['inventory_value'], 'computed_at' => $now,
            'created_at' => $now, 'updated_at' => $now,
        ]);
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

}
