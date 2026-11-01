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
                    ->join('purchases', 'purchases.id', '=', 'stock_lots.purchase_id')
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
                    ->where('purchases.status', 'finalized')
                    ->whereNotNull('stock_lots.unit_cost'),
                'ledger_stock_value'
            )
            ->selectSub(
                DB::table('sale_allocations')
                    ->join('transaction_items', 'transaction_items.id', '=', 'sale_allocations.transaction_item_id')
                    ->join('transactions', 'transactions.id', '=', 'transaction_items.transaction_id')
                    ->selectRaw('COALESCE(SUM(sale_allocations.weight_kg), 0)')
                    ->whereColumn('transaction_items.product_id', 'products.id')
                    ->where('transactions.status', 'completed')
                    ->where('sale_allocations.is_shortfall', true),
                'shortfall_weight_kg'
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
                $product->setAttribute('shortfall_kg', $this->formatWeight((string) $product->getAttribute('shortfall_weight_kg')));
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
