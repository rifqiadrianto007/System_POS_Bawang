<?php

namespace App\Services;

use App\Models\SaleAllocation;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\Transaction;
use App\Models\TransactionItem;
use Carbon\Carbon;
use Illuminate\Support\Collection;

final class ShortfallResolver
{
    /**
     * @param  Collection<int, StockLot>  $lots
     * @return array{kg: string, transactions_count: int, cogs_added: int}
     */
    public function resolveLots(Collection $lots, int $createdBy, Carbon $occurredAt): array
    {
        $resolvedMilli = 0;
        $cogsAdded = 0;
        $transactionIds = [];
        $lotsByProduct = $lots->groupBy('product_id');

        foreach ($lotsByProduct as $productId => $productLots) {
            $availableLots = $productLots
                ->sortBy(fn (StockLot $lot): string => $lot->cleaned_at?->format('Y-m-d').'|'.str_pad((string) $lot->id, 10, '0', STR_PAD_LEFT))
                ->values();
            $allocations = SaleAllocation::query()
                ->select('sale_allocations.*')
                ->join('transaction_items', 'transaction_items.id', '=', 'sale_allocations.transaction_item_id')
                ->join('transactions', 'transactions.id', '=', 'transaction_items.transaction_id')
                ->where('transaction_items.product_id', $productId)
                ->where('transactions.status', 'completed')
                ->where('sale_allocations.is_shortfall', true)
                ->orderBy('transactions.occurred_at')
                ->orderBy('sale_allocations.id')
                ->lockForUpdate()
                ->get();

            foreach ($availableLots as $lot) {
                $availableMilli = $this->lotBalance($lot);
                if ($availableMilli <= 0) {
                    continue;
                }

                foreach ($allocations as $allocation) {
                    if ($availableMilli <= 0 || ! $allocation->is_shortfall) {
                        continue;
                    }

                    $remainingMilli = $this->toMilli((string) $allocation->weight_kg);
                    if ($remainingMilli <= 0) {
                        continue;
                    }

                    $closedMilli = min($remainingMilli, $availableMilli);
                    $item = TransactionItem::query()->findOrFail($allocation->transaction_item_id);
                    $allocation->update([
                        'weight_kg' => $this->formatWeight($closedMilli),
                        'lot_id' => $lot->id,
                        'unit_cost' => (int) $lot->unit_cost,
                        'is_shortfall' => false,
                        'backfilled_at' => now(),
                    ]);

                    if ($remainingMilli > $closedMilli) {
                        SaleAllocation::query()->create([
                            'transaction_item_id' => $allocation->transaction_item_id,
                            'lot_id' => null,
                            'weight_kg' => $this->formatWeight($remainingMilli - $closedMilli),
                            'unit_cost' => 0,
                            'is_shortfall' => true,
                        ]);
                    }

                    StockMovement::query()->create([
                        'product_id' => $item->product_id,
                        'lot_id' => null,
                        'type' => 'count_adjust',
                        'weight_kg' => $this->formatWeight($closedMilli),
                        'unit_cost' => 0,
                        'ref_type' => 'shortfall_resolve',
                        'ref_id' => $allocation->id,
                        'occurred_at' => $occurredAt,
                        'created_by' => $createdBy,
                        'note' => 'Penutupan shortfall',
                    ]);
                    StockMovement::query()->create([
                        'product_id' => $item->product_id,
                        'lot_id' => $lot->id,
                        'type' => 'count_adjust',
                        'weight_kg' => $this->formatWeight(-$closedMilli),
                        'unit_cost' => (int) $lot->unit_cost,
                        'ref_type' => 'shortfall_resolve',
                        'ref_id' => $allocation->id,
                        'occurred_at' => $occurredAt,
                        'created_by' => $createdBy,
                        'note' => 'Penutupan shortfall',
                    ]);

                    $oldCogs = (int) $item->cogs;
                    $newCogs = $this->recalculateCogs($item->fresh());
                    $cogsAdded += $newCogs - $oldCogs;
                    $resolvedMilli += $closedMilli;
                    $availableMilli -= $closedMilli;
                    $transactionIds[] = $item->transaction_id;
                }
            }
        }

        return [
            'kg' => $this->formatWeight($resolvedMilli),
            'transactions_count' => count(array_unique($transactionIds)),
            'cogs_added' => $cogsAdded,
        ];
    }

    public function recalculateCogs(TransactionItem $item): int
    {
        $numerator = 0;
        foreach ($item->saleAllocations()->get() as $allocation) {
            if (! $allocation->is_shortfall) {
                $numerator += $this->toMilli((string) $allocation->weight_kg) * (int) $allocation->unit_cost;
            }
        }

        $cogs = $this->roundHalfUp($numerator, 1000);
        $item->update(['cogs' => $cogs]);

        return $cogs;
    }

    private function lotBalance(StockLot $lot): int
    {
        return max(0, $this->toMilli((string) $lot->movements()->sum('weight_kg')));
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
        return intdiv($numerator * 2 + $denominator, $denominator * 2);
    }
}
