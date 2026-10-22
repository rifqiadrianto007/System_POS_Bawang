<?php

namespace App\Services;

use App\Models\Product;
use App\Models\StockLot;

final class StockService
{
    /**
     * @return list<array<string, mixed>>
     */
    public function summaries(): array
    {
        return Product::query()
            ->with(['stockLots.movements', 'stockMovements'])
            ->orderBy('name')
            ->get()
            ->map(fn (Product $product): array => $this->summarizeProduct($product))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function summarizeProduct(Product $product): array
    {
        $lots = $product->stockLots
            ->sortBy(fn (StockLot $lot): string => $lot->cleaned_at?->format('Y-m-d').'|'.str_pad((string) $lot->id, 10, '0', STR_PAD_LEFT))
            ->map(fn (StockLot $lot): array => $this->summarizeLot($lot))
            ->values()
            ->all();
        $stockMilli = $this->sumMovementWeight($product->stockMovements);

        return [
            'product_id' => $product->id,
            'product_name' => $product->name,
            'stock_weight_kg' => $this->formatWeight($stockMilli),
            'inventory_value' => array_sum(array_column($lots, 'inventory_value')),
            'lots' => $lots,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function summarizeLot(StockLot $lot): array
    {
        $lotMilli = $this->sumMovementWeight($lot->movements);
        $inventoryValue = $lot->unit_cost === null
            ? 0
            : $this->roundHalfUp($lotMilli * (int) $lot->unit_cost, 1000);

        return [
            'lot_id' => $lot->id,
            'product_id' => $lot->product_id,
            'cleaned_at' => $lot->cleaned_at?->format('Y-m-d'),
            'stock_weight_kg' => $this->formatWeight($lotMilli),
            'unit_cost' => $lot->unit_cost,
            'inventory_value' => $inventoryValue,
        ];
    }

    private function sumMovementWeight(iterable $movements): int
    {
        $total = 0;

        foreach ($movements as $movement) {
            $total += $this->toMilliKilograms((string) $movement->weight_kg);
        }

        return $total;
    }

    private function toMilliKilograms(string $weight): int
    {
        $normalized = trim($weight);
        $negative = str_starts_with($normalized, '-');
        $normalized = ltrim($normalized, '+-');
        [$whole, $fraction] = array_pad(explode('.', $normalized, 2), 2, '');
        $value = ((int) $whole * 1000) + (int) str_pad(substr($fraction, 0, 3), 3, '0');

        return $negative ? -$value : $value;
    }

    private function formatWeight(int $milliKilograms): string
    {
        $sign = $milliKilograms < 0 ? '-' : '';
        $absolute = abs($milliKilograms);

        return $sign.intdiv($absolute, 1000).'.'.str_pad((string) ($absolute % 1000), 3, '0', STR_PAD_LEFT);
    }

    private function roundHalfUp(int $numerator, int $denominator): int
    {
        if ($numerator < 0) {
            return -intdiv(abs($numerator) * 2 + $denominator, $denominator * 2);
        }

        return intdiv($numerator * 2 + $denominator, $denominator * 2);
    }
}
