<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Purchase;
use App\Models\StockCount;
use App\Models\StockMovement;

final class StockService
{
    public function __construct(private readonly AverageCostService $averageCost) {}

    /** @return array{products:list<array<string,mixed>>,totals:array{display_kg:string,warehouse_kg:string,stock_value:int}} */
    public function summaries(): array
    {
        $grossPurchaseMilli = 0;
        foreach (Purchase::query()->get(['gross_weight_kg']) as $purchase) {
            $grossPurchaseMilli += $this->averageCost->toMilli((string) ($purchase->gross_weight_kg ?? '0'));
        }
        $balances = StockMovement::query()->select('product_id')
            ->selectRaw('COALESCE(SUM(weight_kg),0) as stock_kg, COALESCE(SUM(value_delta),0) as stock_value')
            ->groupBy('product_id')->get()->keyBy('product_id');
        $counts = StockCount::query()->where('status', 'active')->select('product_id')
            ->selectRaw('MAX(counted_at) as counted_at')->groupBy('product_id')->pluck('counted_at', 'product_id');
        $products = Product::query()->orderBy('name')->get()->map(function (Product $product) use ($balances, $counts): array {
            $row = $balances->get($product->id);
            $stockMilli = $this->averageCost->toMilli((string) ($row->stock_kg ?? '0'));
            $stockValue = (int) ($row->stock_value ?? 0);
            $avgCost = null;
            if ($stockMilli > 0) {
                $numerator = bcmul((string) $stockValue, '1000', 0);
                $negative = str_starts_with($numerator, '-');
                $absolute = ltrim($numerator, '-');
                $quotient = (int) bcdiv($absolute, (string) $stockMilli, 0);
                $remainder = (int) bcmod($absolute, (string) $stockMilli);
                $avgCost = $quotient + ($remainder * 2 >= $stockMilli ? 1 : 0);
                if ($negative) {
                    $avgCost *= -1;
                }
            }

            return [
                'product_id' => (int) $product->id, 'name' => $product->name, 'jenis' => $product->jenis,
                'is_active' => (bool) $product->is_active, 'stock_kg' => $this->averageCost->formatWeight($stockMilli),
                'shortfall_kg' => $this->averageCost->formatWeight(max(0, -$stockMilli)),
                'stock_value' => $stockValue, 'avg_cost' => $avgCost ?? 0, 'last_count_date' => $counts[$product->id] ?? null,
            ];
        })->all();
        $displayMilli = 0;
        $value = 0;
        foreach ($products as $product) {
            $displayMilli += max(0, $this->averageCost->toMilli($product['stock_kg']));
            $value += (int) $product['stock_value'];
        }
        $warehouseMilli = max(0, $grossPurchaseMilli - $displayMilli);

        return ['products' => $products, 'totals' => [
            'display_kg' => $this->averageCost->formatWeight($displayMilli),
            'warehouse_kg' => $this->averageCost->formatWeight($warehouseMilli), 'stock_value' => $value, 'inventory_value' => $value,
        ]];
    }
}
