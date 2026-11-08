<?php

namespace App\Services;

use App\Models\Product;
use App\Models\StockMovement;

final class AverageCostService
{
    /** @return array{stock_kg:string,stock_value:int,avg_cost:?int} */
    public function stock(Product|int $product): array
    {
        $id = $product instanceof Product ? $product->id : $product;
        $row = StockMovement::query()->where('product_id', $id)
            ->selectRaw('COALESCE(SUM(weight_kg), 0) AS stock_kg, COALESCE(SUM(value_delta), 0) AS stock_value')->first();
        $weight = (string) $row->stock_kg;
        $value = (int) $row->stock_value;
        $milli = $this->toMilli($weight);
        $avg = null;
        if ($milli > 0) {
            $numerator = bcmul((string) $value, '1000', 0);
            $negative = str_starts_with($numerator, '-');
            $absolute = ltrim($numerator, '-');
            $q = (int) bcdiv($absolute, (string) $milli, 0);
            $r = (int) bcmod($absolute, (string) $milli);
            $avg = $q + ($r * 2 >= $milli ? 1 : 0);
            if ($negative) {
                $avg *= -1;
            }
        }

        return ['stock_kg' => $this->formatWeight($milli), 'stock_value' => $value, 'avg_cost' => $avg];
    }

    public function cogs(int $weightMilli, int $stockMilli, int $stockValue): int
    {
        if ($weightMilli === $stockMilli && $stockMilli > 0) {
            return $stockValue;
        }
        if ($stockMilli <= 0) {
            return 0;
        }
        $numerator = bcmul((string) $weightMilli, (string) $stockValue, 0);
        $negative = str_starts_with($numerator, '-');
        $absolute = ltrim($numerator, '-');
        $quotient = (int) bcdiv($absolute, (string) $stockMilli, 0);
        $remainder = (int) bcmod($absolute, (string) $stockMilli);
        $result = $quotient + ($remainder * 2 >= $stockMilli ? 1 : 0);

        return $negative ? -$result : $result;
    }

    public function valueForWeight(int $weightMilli, int $pricePerKg): int
    {
        $numerator = bcmul((string) $weightMilli, (string) $pricePerKg, 0);
        $quotient = (int) bcdiv($numerator, '1000', 0);

        return $quotient + ((int) bcmod($numerator, '1000') >= 500 ? 1 : 0);
    }

    public function totalCost(string $weightKg, int $pricePerKg): int
    {
        return $this->valueForWeight($this->toMilli($weightKg), $pricePerKg);
    }

    public function updateLastUnitCost(Product $product): void
    {
        $stock = $this->stock($product);
        if ($this->toMilli($stock['stock_kg']) > 0 && $stock['avg_cost'] !== null) {
            $product->forceFill(['last_unit_cost' => $stock['avg_cost']])->save();
        }
    }

    public function toMilli(string $weight): int
    {
        $weight = trim($weight);
        $negative = str_starts_with($weight, '-');
        [$whole, $fraction] = array_pad(explode('.', ltrim($weight, '+-'), 2), 2, '');
        $value = ((int) $whole * 1000) + (int) str_pad(substr($fraction, 0, 3), 3, '0');

        return $negative ? -$value : $value;
    }

    public function formatWeight(int $milli): string
    {
        $sign = $milli < 0 ? '-' : '';
        $absolute = abs($milli);

        return $sign.intdiv($absolute, 1000).'.'.str_pad((string) ($absolute % 1000), 3, '0', STR_PAD_LEFT);
    }

    private function divideHalfUp(int $numerator, int $denominator): int
    {
        if ($denominator <= 0) {
            return 0;
        }
        $quotient = intdiv($numerator, $denominator);

        return $quotient + (($numerator % $denominator) * 2 >= $denominator ? 1 : 0);
    }
}
