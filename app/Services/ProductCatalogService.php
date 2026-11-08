<?php

namespace App\Services;

use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Support\Collection;

final class ProductCatalogService
{
    public function __construct(private readonly AverageCostService $averageCost) {}

    /** @param array<string,mixed> $filters @return Collection<int,Product> */
    public function list(array $filters, string $role): Collection
    {
        $query = Product::query();
        if ($role === 'kasir') {
            $query->where('is_active', true);
        } elseif (isset($filters['active'])) {
            $query->where('is_active', (bool) (int) $filters['active']);
        }

        $products = $query->orderBy('name')->get();
        if ($role !== 'owner' || $products->isEmpty()) {
            return $products;
        }
        $balances = StockMovement::query()->whereIn('product_id', $products->modelKeys())
            ->select('product_id')->selectRaw('COALESCE(SUM(weight_kg),0) AS stock_kg, COALESCE(SUM(value_delta),0) AS stock_value')
            ->groupBy('product_id')->get()->keyBy('product_id');

        return $products->map(function (Product $product) use ($balances): Product {
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
            $product->setAttribute('stock_kg', $this->averageCost->formatWeight($stockMilli));
            $product->setAttribute('stock_value', $stockValue);
            $product->setAttribute('avg_cost', $avgCost);
            $product->setAttribute('shortfall_kg', $this->averageCost->formatWeight(max(0, -$stockMilli)));

            return $product;
        });
    }
}
