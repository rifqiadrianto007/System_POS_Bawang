<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\StockCount;
use App\Models\StockMovement;
use App\Models\Transaction;
use App\Models\TransactionItem;
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
        [$start, $end, $periodLabel, $localDates] = $this->periodBounds($filters);
        $transactions = Transaction::query()->whereBetween('occurred_at', [$start, $end]);
        $completedIds = (clone $transactions)->where('status', 'completed')->pluck('id');
        $netSales = (clone $transactions)->where('status', 'completed')->sum('total');
        $cogs = TransactionItem::query()->whereIn('transaction_id', $completedIds)->sum('cogs');
        $displayShrinkValue = StockCount::query()->whereBetween('counted_at', [$start, $end])->sum('loss_value');
        $expenseQuery = Expense::query();
        $purchaseQuery = Purchase::query()->where('status', 'finalized');
        if ($filters['period'] === 'daily') {
            $expenseQuery->whereDate('spent_at', $localDates['start']);
            $purchaseQuery->whereDate('purchased_at', $localDates['start']);
        } else {
            $expenseQuery->whereBetween('spent_at', [$localDates['start'], $localDates['end']]);
            $purchaseQuery->whereBetween('purchased_at', [$localDates['start'], $localDates['end']]);
        }
        $operatingExpenses = $expenseQuery->sum('amount');
        $purchasesTotal = (clone $purchaseQuery)->sum('total_cost');
        $cleaningShrink = 0;
        foreach ((clone $purchaseQuery)->get(['gross_weight_kg', 'clean_weight_kg']) as $purchase) {
            $cleaningShrink += $this->toMilli((string) $purchase->gross_weight_kg)
                - $this->toMilli((string) $purchase->clean_weight_kg);
        }
        $shortfallCount = DB::table('sale_allocations')
            ->join('transaction_items', 'transaction_items.id', '=', 'sale_allocations.transaction_item_id')
            ->whereIn('transaction_items.transaction_id', $completedIds)
            ->where('sale_allocations.is_shortfall', true)
            ->count();
        $voidCount = (clone $transactions)->where('status', 'void')->count();
        $netProfit = $netSales - $cogs - $displayShrinkValue - $operatingExpenses;

        return [
            'period' => $filters['period'],
            $periodLabel['key'] => $periodLabel['value'],
            'net_sales' => (int) $netSales,
            'cogs' => (int) $cogs,
            'display_shrink_value' => (int) $displayShrinkValue,
            'operating_expenses' => (int) $operatingExpenses,
            'net_profit' => $netProfit,
            'purchases_total' => (int) $purchasesTotal,
            'cash_flow' => $netSales - $purchasesTotal - $operatingExpenses,
            'cleaning_shrink_kg' => $this->formatWeight($cleaningShrink),
            'shortfall_count' => $shortfallCount,
            'void_count' => $voidCount,
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
            ->whereBetween('transactions.occurred_at', [$start, $end])
            ->get();
        $shortfalls = DB::table('sale_allocations')
            ->join('transaction_items', 'transaction_items.id', '=', 'sale_allocations.transaction_item_id')
            ->join('transactions', 'transactions.id', '=', 'transaction_items.transaction_id')
            ->where('transactions.status', 'completed')
            ->whereBetween('transactions.occurred_at', [$start, $end])
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
        $date = (string) ($filters['date'] ?? now('Asia/Jakarta')->toDateString());
        [$start, $end] = $this->dateBounds($date, $date);
        $movements = StockMovement::query()->whereBetween('occurred_at', [$start, $end])->get();
        $counts = StockCount::query()->whereBetween('counted_at', [$start, $end])->get();
        $products = Product::query()->with('stockMovements')->orderBy('name')->get();
        $summary = [
            'received_weight_kg' => 0,
            'stock_weight_kg' => 0,
            'sold_weight_kg' => 0,
            'cleaning_shrink_kg' => 0,
            'display_shrink_kg' => 0,
            'display_shrink_value' => 0,
        ];
        $cleaningShrink = 0;
        foreach (Purchase::query()->where('status', 'finalized')->whereDate('purchased_at', $date)
            ->get(['gross_weight_kg', 'clean_weight_kg']) as $purchase) {
            $cleaningShrink += $this->toMilli((string) $purchase->gross_weight_kg)
                - $this->toMilli((string) $purchase->clean_weight_kg);
        }
        $productRows = [];
        foreach ($products as $product) {
            $received = 0;
            $sold = 0;
            foreach ($movements->where('product_id', $product->id) as $movement) {
                $weight = $this->toMilli((string) $movement->weight_kg);
                if ($movement->type === 'in_clean' && $weight > 0) {
                    $received += $weight;
                }
                if ($movement->type === 'sale' && $weight < 0) {
                    $sold += abs($weight);
                }
            }
            $shrink = 0;
            $shrinkValue = 0;
            foreach ($counts->where('product_id', $product->id) as $count) {
                $diff = $this->toMilli((string) $count->diff_kg);
                if ($diff < 0) {
                    $shrink += abs($diff);
                    $shrinkValue += (int) $count->loss_value;
                }
            }
            $stock = $this->sumMovementWeights($product->stockMovements);
            $productRows[] = [
                'product_id' => $product->id,
                'product_name' => $product->name,
                'received_weight_kg' => $this->formatWeight($received),
                'stock_weight_kg' => $this->formatWeight($stock),
                'sold_weight_kg' => $this->formatWeight($sold),
                'display_shrink_kg' => $this->formatWeight($shrink),
                'display_shrink_value' => $shrinkValue,
            ];
            $summary['received_weight_kg'] += $received;
            $summary['stock_weight_kg'] += $stock;
            $summary['sold_weight_kg'] += $sold;
            $summary['display_shrink_kg'] += $shrink;
            $summary['display_shrink_value'] += $shrinkValue;
        }
        foreach (['received_weight_kg', 'stock_weight_kg', 'sold_weight_kg', 'display_shrink_kg'] as $key) {
            $summary[$key] = $this->formatWeight($summary[$key]);
        }
        $summary['cleaning_shrink_kg'] = $this->formatWeight($cleaningShrink);

        return ['date' => $date, 'summary' => $summary, 'products' => $productRows];
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
            ->whereBetween('occurred_at', [$start, $end])
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

    /** @return array{0: Carbon, 1: Carbon, 2: array{key: string, value: string}, 3: array{start: string, end: string}} */
    private function periodBounds(array $filters): array
    {
        if ($filters['period'] === 'daily') {
            $date = Carbon::createFromFormat('Y-m-d', (string) $filters['date'], 'Asia/Jakarta');

            return [$date->copy()->startOfDay()->utc(), $date->copy()->endOfDay()->utc(), ['key' => 'date', 'value' => $date->toDateString()], ['start' => $date->toDateString(), 'end' => $date->toDateString()]];
        }
        $month = Carbon::createFromFormat('Y-m', (string) $filters['month'], 'Asia/Jakarta');

        return [$month->copy()->startOfMonth()->startOfDay()->utc(), $month->copy()->endOfMonth()->endOfDay()->utc(), ['key' => 'month', 'value' => $month->format('Y-m')], ['start' => $month->copy()->startOfMonth()->toDateString(), 'end' => $month->copy()->endOfMonth()->toDateString()]];
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function dateBounds(string $from, string $to): array
    {
        $start = Carbon::createFromFormat('Y-m-d', $from, 'Asia/Jakarta')->startOfDay();
        $end = Carbon::createFromFormat('Y-m-d', $to, 'Asia/Jakarta')->endOfDay();

        return [$start->copy()->utc(), $end->copy()->utc()];
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
