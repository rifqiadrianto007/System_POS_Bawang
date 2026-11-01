<?php

namespace App\Services;

use App\Models\Product;
use App\Models\AuditLog;
use App\Models\StockCount;
use App\Models\StockLot;
use App\Models\StockMovement;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

final class StockCountService
{
    /** @param array<string, mixed> $attributes */
    public function create(array $attributes, int $ownerId): StockCount
    {
        return DB::transaction(function () use ($attributes, $ownerId): StockCount {
            $product = Product::query()
                ->with([
                    'stockLots' => fn ($query) => $query->whereHas(
                        'purchase',
                        fn ($purchase) => $purchase->where('status', 'finalized')
                    ),
                    'stockLots.movements',
                ])
                ->lockForUpdate()
                ->findOrFail($attributes['product_id']);
            $lots = $product->stockLots->sortBy(fn (StockLot $lot): string => $lot->cleaned_at?->format('Y-m-d').'|'.$lot->id)->values();
            $systemMilli = $this->lotsRemaining($lots);
            $countedMilli = $this->toMilli((string) $attributes['counted_weight_kg']);
            $diffMilli = $countedMilli - $systemMilli;
            $countedAt = Carbon::parse((string) $attributes['counted_at']);
            $count = StockCount::query()->create([
                'product_id' => $product->id,
                'counted_weight_kg' => $this->formatWeight($countedMilli),
                'system_weight_kg' => $this->formatWeight($systemMilli),
                'diff_kg' => $this->formatWeight($diffMilli),
                'unit_cost_avg' => 0,
                'loss_value' => 0,
                'counted_at' => $countedAt,
                'counted_by' => $ownerId,
                'notes' => $attributes['notes'] ?? null,
            ]);

            [$lossValue, $unitCostAvg] = $this->applyLotAdjustment($product, $lots, $diffMilli, $countedAt, $ownerId, $count->id);
            $count->update(['unit_cost_avg' => $unitCostAvg, 'loss_value' => $lossValue]);

            return $count->fresh()->load('product');
        });
    }

    public function list(?string $status = null): Collection
    {
        return StockCount::query()->with('product')
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->latest('counted_at')->latest('id')->get();
    }

    public function cancel(StockCount $stockCount, string $reason, int $ownerId): StockCount
    {
        return DB::transaction(function () use ($stockCount, $reason, $ownerId): StockCount {
            $count = StockCount::query()->lockForUpdate()->findOrFail($stockCount->id);
            if (($count->status ?? 'active') === 'cancelled') {
                throw new LogicException('Hitung ulang ini sudah dibatalkan.');
            }

            $newer = StockCount::query()
                ->where('product_id', $count->product_id)
                ->where('status', 'active')
                ->where(function ($query) use ($count): void {
                    $query->where('counted_at', '>', $count->counted_at)
                        ->orWhere(function ($nested) use ($count): void {
                            $nested->where('counted_at', $count->counted_at)->where('id', '>', $count->id);
                        });
                })
                ->exists();
            if ($newer) {
                throw new LogicException('Hanya hitung ulang terakhir untuk produk ini yang dapat dibatalkan.');
            }

            $now = now();
            $movements = StockMovement::query()
                ->where('ref_type', 'stock_count')
                ->where('ref_id', $count->id)
                ->get();
            foreach ($movements as $movement) {
                StockMovement::query()->create([
                    'product_id' => $movement->product_id,
                    'lot_id' => $movement->lot_id,
                    'type' => 'count_adjust',
                    'weight_kg' => $this->formatWeight(-$this->toMilli((string) $movement->weight_kg)),
                    'unit_cost' => $movement->unit_cost,
                    'ref_type' => 'stock_count_cancelled',
                    'ref_id' => $count->id,
                    'occurred_at' => $now,
                    'created_by' => $ownerId,
                    'note' => 'Pembalik pembatalan hitung ulang',
                ]);
            }

            $owner = \App\Models\User::query()->findOrFail($ownerId);
            $count->update([
                'status' => 'cancelled',
                'cancelled_at' => $now,
                'cancelled_by_name' => $owner->name,
                'cancel_reason' => $reason,
            ]);
            AuditLog::query()->create([
                'user_id' => $ownerId,
                'action' => 'stock_count.cancelled',
                'entity_type' => StockCount::class,
                'entity_id' => $count->id,
                'payload' => [
                    'product_id' => $count->product_id,
                    'diff_kg' => $this->formatWeight($this->toMilli((string) $count->diff_kg)),
                    'loss_value' => (int) $count->loss_value,
                    'reason' => $reason,
                ],
            ]);

            $product = Product::query()->with([
                'stockLots' => fn ($query) => $query->whereHas(
                    'purchase',
                    fn ($purchase) => $purchase->where('status', 'finalized')
                ),
                'stockLots.movements',
            ])->findOrFail($count->product_id);
            app(ShortfallResolver::class)->resolveLots($product->stockLots, $ownerId, $now);

            return $count->fresh()->load('product');
        });
    }

    /** @return array{0: int, 1: int} */
    private function applyLotAdjustment(
        Product $product,
        Collection $lots,
        int $diffMilli,
        Carbon $countedAt,
        int $ownerId,
        int $countId
    ): array
    {
        if ($diffMilli === 0 || $lots->isEmpty()) {
            return [0, 0];
        }

        $remaining = abs($diffMilli);
        $lossValue = 0;
        if ($diffMilli < 0) {
            $orderedLots = $lots;
        } else {
            $orderedLots = $lots->reverse()->values();
        }

        foreach ($orderedLots as $lot) {
            if ($remaining <= 0) {
                break;
            }
            $available = max(0, $this->sumWeights($lot->movements));
            if ($diffMilli < 0) {
                $adjustment = min($remaining, $available);
            } else {
                $adjustment = $remaining;
            }
            if ($adjustment <= 0) {
                continue;
            }

            $unitCost = (int) $lot->unit_cost;
            $lossValue += $diffMilli < 0
                ? $this->roundHalfUp($adjustment * $unitCost, 1000)
                : -$this->roundHalfUp($adjustment * $unitCost, 1000);
            StockMovement::query()->create([
                'product_id' => $product->id,
                'lot_id' => $lot->id,
                'type' => 'count_adjust',
                'weight_kg' => $this->formatWeight($diffMilli < 0 ? -$adjustment : $adjustment),
                'unit_cost' => $unitCost,
                'ref_type' => 'stock_count',
                'ref_id' => $countId,
                'occurred_at' => $countedAt,
                'created_by' => $ownerId,
                'note' => 'Penyesuaian hitung ulang display',
            ]);
            $remaining -= $adjustment;
            if ($diffMilli > 0) {
                break;
            }
        }

        $unitCostAvg = $this->roundHalfUp($lossValue * 1000, abs($diffMilli));

        return [$lossValue, $unitCostAvg];
    }

    private function lotsRemaining(Collection $lots): int
    {
        return $lots->sum(fn (StockLot $lot): int => max(0, $this->sumWeights($lot->movements)));
    }

    private function sumWeights(iterable $movements): int
    {
        $total = 0;
        foreach ($movements as $movement) {
            $total += $this->toMilli((string) $movement->weight_kg);
        }

        return $total;
    }

    private function toMilli(string $weight): int
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
