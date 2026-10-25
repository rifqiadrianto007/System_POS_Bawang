<?php

namespace App\Services;

use App\Models\Product;
use App\Models\StockCount;
use App\Models\StockMovement;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class StockCountService
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes, int $ownerId): StockCount
    {
        return DB::transaction(function () use ($attributes, $ownerId): StockCount {
            $product = Product::query()
                ->with(['stockMovements', 'stockLots.movements'])
                ->lockForUpdate()
                ->findOrFail($attributes['product_id']);
            $systemMilli = $this->sumWeights($product->stockMovements);
            $countedMilli = $this->toMilliKilograms((string) $attributes['counted_weight_kg']);
            $diffMilli = $countedMilli - $systemMilli;
            $unitCostAvg = $this->weightedAverageUnitCost($product->stockLots);
            $lossValue = $this->roundHalfUp(abs($diffMilli) * $unitCostAvg, 1000);
            if ($diffMilli > 0) {
                $lossValue *= -1;
            }
            $countedAt = Carbon::parse((string) $attributes['counted_at']);

            $count = StockCount::query()->create([
                'product_id' => $product->id,
                'counted_weight_kg' => $this->formatWeight($countedMilli),
                'system_weight_kg' => $this->formatWeight($systemMilli),
                'diff_kg' => $this->formatWeight($diffMilli),
                'unit_cost_avg' => $unitCostAvg,
                'loss_value' => $lossValue,
                'counted_at' => $countedAt,
                'counted_by' => $ownerId,
                'notes' => $attributes['notes'] ?? null,
            ]);

            StockMovement::query()->create([
                'product_id' => $product->id,
                'lot_id' => null,
                'type' => 'count_adjust',
                'weight_kg' => $this->formatWeight($diffMilli),
                'unit_cost' => $unitCostAvg,
                'ref_type' => StockCount::class,
                'ref_id' => $count->id,
                'occurred_at' => $countedAt,
                'created_by' => $ownerId,
                'note' => 'Penyesuaian hitung ulang display',
            ]);

            return $count->load('product');
        });
    }

    public function list(): Collection
    {
        return StockCount::query()
            ->with('product')
            ->latest('counted_at')
            ->get();
    }

    private function weightedAverageUnitCost(Collection $lots): int
    {
        $totalWeight = 0;
        $weightedCost = 0;
        foreach ($lots as $lot) {
            $weight = $this->sumWeights($lot->movements);
            if ($weight <= 0 || $lot->unit_cost === null) {
                continue;
            }
            $totalWeight += $weight;
            $weightedCost += $weight * (int) $lot->unit_cost;
        }

        return $totalWeight === 0 ? 0 : $this->roundHalfUp($weightedCost, $totalWeight);
    }

    private function sumWeights(iterable $movements): int
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

    private function formatWeight(int $weightMilli): string
    {
        $sign = $weightMilli < 0 ? '-' : '';
        $absolute = abs($weightMilli);

        return $sign.intdiv($absolute, 1000).'.'.str_pad((string) ($absolute % 1000), 3, '0', STR_PAD_LEFT);
    }

    private function roundHalfUp(int $numerator, int $denominator): int
    {
        return $numerator < 0
            ? -intdiv(abs($numerator) * 2 + $denominator, $denominator * 2)
            : intdiv($numerator * 2 + $denominator, $denominator * 2);
    }
}
