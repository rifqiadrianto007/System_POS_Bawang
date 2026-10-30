<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class ProductCatalogService
{
    /** @param array<string, mixed> $filters
     *  @return Collection<int, Product>
     */
    public function list(array $filters, string $role): Collection
    {
        $products = Product::query()
            ->select('products.*')
            ->selectSub(
                DB::table('stock_movements')
                    ->selectRaw('COALESCE(SUM(weight_kg), 0)')
                    ->whereColumn('stock_movements.product_id', 'products.id'),
                'ledger_stock_kg'
            )
            ->selectSub(
                DB::table('stock_lots')
                    ->joinSub(
                        DB::table('stock_movements')
                            ->select('lot_id')
                            ->selectRaw('SUM(weight_kg) as remaining_weight_kg')
                            ->whereNotNull('lot_id')
                            ->groupBy('lot_id'),
                        'lot_balances',
                        'lot_balances.lot_id',
                        '=',
                        'stock_lots.id'
                    )
                    ->selectRaw('COALESCE(SUM(ROUND(lot_balances.remaining_weight_kg * stock_lots.unit_cost, 0)), 0)')
                    ->whereColumn('stock_lots.product_id', 'products.id')
                    ->whereNotNull('stock_lots.unit_cost'),
                'ledger_stock_value'
            );

        if ($role === 'kasir') {
            $products->where('is_active', true);
        } elseif (isset($filters['active'])) {
            $products->where('is_active', (bool) (int) $filters['active']);
        }

        return $products->orderBy('name')->get()->map(function (Product $product) use ($role): Product {
            if ($role === 'owner') {
                $product->setAttribute('stock_kg', $this->formatWeight((string) $product->getAttribute('ledger_stock_kg')));
                $product->setAttribute('stock_value', (int) $product->getAttribute('ledger_stock_value'));
            }

            return $product;
        });
    }

    private function formatWeight(string $weight): string
    {
        $normalized = trim($weight);
        $negative = str_starts_with($normalized, '-');
        $normalized = ltrim($normalized, '+-');
        [$whole, $fraction] = array_pad(explode('.', $normalized, 2), 2, '');
        $whole = ltrim($whole, '0');
        $whole = $whole === '' ? '0' : $whole;
        $fraction = str_pad(substr($fraction, 0, 3), 3, '0');
        $formatted = $whole.'.'.$fraction;

        return $negative && $formatted !== '0.000' ? '-'.$formatted : $formatted;
    }
}
