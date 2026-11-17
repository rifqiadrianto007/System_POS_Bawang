<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Purchase;
use App\Models\StockAddition;
use App\Models\StockCount;
use App\Models\StockMovement;
use App\Models\TransactionItem;
use App\Support\BusinessDay;
use Illuminate\Support\Collection;

final class StockService
{
    public function __construct(private readonly AverageCostService $averageCost) {}

    /** @return array{products:list<array<string,mixed>>,totals:array<string,string|int>} */
    public function summaries(string $from, string $to): array
    {
        [$start, $end] = BusinessDay::between($from, $to);
        $purchases = Purchase::query()->withSum('stockAdditions as added_kg', 'weight_kg')->get();
        $unsortedMilli = $unsortedValue = 0;
        foreach ($purchases as $purchase) {
            if ($purchase->sorted_finished_at !== null) {
                continue;
            }
            $remaining = max(0, $this->averageCost->toMilli((string) $purchase->gross_weight_kg)
                - $this->averageCost->toMilli((string) ($purchase->added_kg ?? '0')));
            $unsortedMilli += $remaining;
            $unsortedValue += $this->averageCost->valueForWeight($remaining, (int) $purchase->price_per_kg);
        }

        $balances = StockMovement::query()->select('product_id')
            ->selectRaw('COALESCE(SUM(weight_kg),0) as stock_kg, COALESCE(SUM(value_delta),0) as stock_value')
            ->groupBy('product_id')->get()->keyBy('product_id');
        $counts = StockCount::query()->where('status', 'active')->select('product_id')
            ->selectRaw('MAX(counted_at) as counted_at')->groupBy('product_id')->pluck('counted_at', 'product_id');
        $soldByProduct = TransactionItem::query()->join('transactions', 'transactions.id', '=', 'transaction_items.transaction_id')
            ->where('transactions.status', 'completed')->where('transactions.occurred_at', '>=', $start)->where('transactions.occurred_at', '<', $end)
            ->select('transaction_items.product_id')->selectRaw('SUM(transaction_items.weight_kg) AS sold_kg')
            ->groupBy('transaction_items.product_id')->pluck('sold_kg', 'product_id');
        $shrinkByProduct = StockCount::query()->where('status', 'active')->where('counted_at', '>=', $start)->where('counted_at', '<', $end)
            ->select('product_id')->selectRaw('SUM(CASE WHEN diff_kg < 0 THEN -diff_kg ELSE 0 END) AS shrink_kg, SUM(CASE WHEN loss_value > 0 THEN loss_value ELSE 0 END) AS shrink_value')
            ->groupBy('product_id')->get()->keyBy('product_id');
        $products = Product::query()->orderBy('name')->get()->map(function (Product $product) use ($balances, $counts, $soldByProduct, $shrinkByProduct): array {
            $row = $balances->get($product->id);
            $shrink = $shrinkByProduct->get($product->id);
            $weightMilli = $this->averageCost->toMilli((string) ($row->stock_kg ?? '0'));
            $value = (int) ($row->stock_value ?? 0);
            $avgCost = $weightMilli > 0 ? $this->averageCost->averageUnitCost($weightMilli, $value) : 0;

            return [
                'product_id' => (int) $product->id, 'name' => $product->name, 'jenis' => $product->jenis,
                'is_active' => (bool) $product->is_active, 'stock_kg' => $this->averageCost->formatWeight($weightMilli),
                'shortfall_kg' => $this->averageCost->formatWeight(max(0, -$weightMilli)),
                'stock_value' => $value, 'avg_cost' => $avgCost,
                'sold_kg' => $this->averageCost->formatWeight($this->averageCost->toMilli((string) ($soldByProduct[$product->id] ?? '0'))),
                'display_shrink_kg' => $this->averageCost->formatWeight($this->averageCost->toMilli((string) ($shrink->shrink_kg ?? '0'))),
                'display_shrink_value' => (int) ($shrink->shrink_value ?? 0),
                'last_count_date' => $counts[$product->id] ?? null,
            ];
        })->all();

        $displayMilli = 0;
        $displayValue = 0;
        foreach ($products as $product) {
            $displayMilli += $this->averageCost->toMilli($product['stock_kg']);
            $displayValue += (int) $product['stock_value'];
        }
        $soldMilli = array_sum(array_map(fn (array $product): int => $this->averageCost->toMilli($product['sold_kg']), $products));
        $displayShrink = StockCount::query()->where('status', 'active')->where('counted_at', '>=', $start)->where('counted_at', '<', $end)
            ->selectRaw('COALESCE(SUM(CASE WHEN diff_kg < 0 THEN -diff_kg ELSE 0 END),0) as kg, COALESCE(SUM(CASE WHEN loss_value > 0 THEN loss_value ELSE 0 END),0) as value')->first();
        $cleaningShrinkKg = Purchase::query()->whereNotNull('sorted_finished_at')->where('sorted_finished_at', '>=', $start)->where('sorted_finished_at', '<', $end)
            ->sum('cleaning_shrink_kg');
        $cleaningShrinkValue = (int) Purchase::query()->whereNotNull('sorted_finished_at')->where('sorted_finished_at', '>=', $start)->where('sorted_finished_at', '<', $end)
            ->sum('cleaning_loss_value');
        $shrinkMilli = $this->averageCost->toMilli((string) $displayShrink->kg) + $this->averageCost->toMilli((string) $cleaningShrinkKg);
        $shrinkValue = (int) $displayShrink->value + $cleaningShrinkValue;

        return [
            'products' => $products,
            'totals' => [
                'from' => $from, 'to' => $to,
                'display_kg' => $this->averageCost->formatWeight($displayMilli), 'display_value' => $displayValue,
                'stock_value' => $displayValue,
                'unsorted_kg' => $this->averageCost->formatWeight($unsortedMilli), 'unsorted_value' => $unsortedValue,
                'sold_kg' => $this->averageCost->formatWeight($soldMilli),
                'shrink_kg' => $this->averageCost->formatWeight($shrinkMilli), 'shrink_value' => $shrinkValue,
                'inventory_value' => $displayValue + $unsortedValue,
            ],
        ];
    }

    /** @return Collection<int,array{batch_id:int,batch_name:string,added_weight_kg:string}> */
    public function batchesForProduct(Product $product): Collection
    {
        $rows = StockAddition::query()->join('purchases', 'purchases.id', '=', 'stock_additions.purchase_id')
            ->where('stock_additions.product_id', $product->id)
            ->select('stock_additions.purchase_id', 'purchases.code')
            ->selectRaw('SUM(stock_additions.weight_kg) as added_weight_kg')
            ->groupBy('stock_additions.purchase_id', 'purchases.code')
            ->orderBy('purchases.purchased_at')->orderBy('stock_additions.purchase_id')->get();

        return $rows->map(fn ($row): array => [
            'batch_id' => (int) $row->purchase_id,
            'batch_name' => (string) $row->code,
            'added_weight_kg' => $this->averageCost->formatWeight($this->averageCost->toMilli((string) $row->added_weight_kg)),
        ]);
    }
}
