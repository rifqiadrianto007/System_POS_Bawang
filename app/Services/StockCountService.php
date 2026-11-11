<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\StockCount;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

final class StockCountService
{
    public function __construct(private readonly AverageCostService $averageCost) {}

    /** @param array<string,mixed> $attributes */
    public function create(array $attributes, int $ownerId): StockCount
    {
        return DB::transaction(function () use ($attributes, $ownerId): StockCount {
            $product = Product::query()->lockForUpdate()->findOrFail($attributes['product_id']);
            $purchase = Purchase::query()->lockForUpdate()->findOrFail($attributes['purchase_id']);
            if ($purchase->jenis !== $product->jenis) {
                throw \Illuminate\Validation\ValidationException::withMessages(['product_id' => 'Jenis produk harus sama dengan jenis kulak.']);
            }
            $balance = $this->averageCost->stock($product);
            $lot = StockLot::query()->where('purchase_id', $purchase->id)->where('product_id', $product->id)->lockForUpdate()->first();
            $newBatchStock = $lot === null;
            $systemMilli = $lot === null ? 0 : $this->lotBalanceMilli($lot->id);
            $countedMilli = $this->averageCost->toMilli((string) $attributes['counted_weight_kg']);
            $diffMilli = $countedMilli - $systemMilli;
            $occurredAt = Carbon::parse((string) $attributes['counted_at']);
            $avgCost = $balance['avg_cost'] ?? (int) $purchase->price_per_kg;
            $lossValue = 0;
            $valueDelta = 0;

            if ($newBatchStock && $countedMilli > 0) {
                $valueDelta = $this->averageCost->valueForWeight($countedMilli, (int) $purchase->price_per_kg);
            } elseif ($diffMilli !== 0) {
                $valueDelta = $this->averageCost->valueForWeight(abs($diffMilli), $avgCost) * ($diffMilli > 0 ? 1 : -1);
                if ($balance['stock_kg'] !== '0.000' && $this->averageCost->toMilli($balance['stock_kg']) > 0) {
                    $lossValue = -$valueDelta;
                }
            }

            if ($lot === null && $countedMilli > 0) {
                $lot = $purchase->stockLots()->create([
                    'product_id' => $product->id,
                    'weight_kg' => $this->averageCost->formatWeight($countedMilli),
                    'sorted_at' => $occurredAt->copy()->setTimezone('Asia/Jakarta')->toDateString(),
                    'unit_cost' => (int) $purchase->price_per_kg,
                    'notes' => 'Stok display tercatat melalui hitung ulang',
                    'created_by' => $ownerId,
                ]);
            }
            if ($lot !== null) {
                $lot->update(['weight_kg' => $this->averageCost->formatWeight($countedMilli)]);
            }

            $count = StockCount::query()->create([
                'product_id' => $product->id,
                'purchase_id' => $purchase->id,
                'counted_weight_kg' => $this->averageCost->formatWeight($countedMilli),
                'system_weight_kg' => $this->averageCost->formatWeight($systemMilli),
                'diff_kg' => $this->averageCost->formatWeight($diffMilli),
                'unit_cost_avg' => $avgCost,
                'loss_value' => $lossValue,
                'counted_at' => $occurredAt,
                'counted_by' => $ownerId,
                'notes' => $attributes['notes'] ?? null,
            ]);

            AuditLog::query()->create([
                'user_id' => $ownerId,
                'action' => 'stock_count.created',
                'entity_type' => StockCount::class,
                'entity_id' => $count->id,
                'payload' => [
                    'product_id' => $product->id,
                    'purchase_id' => $purchase->id,
                    'counted_weight_kg' => $this->averageCost->formatWeight($countedMilli),
                    'system_weight_kg' => $this->averageCost->formatWeight($systemMilli),
                    'diff_kg' => $this->averageCost->formatWeight($diffMilli),
                    'loss_value' => $lossValue,
                ],
            ]);

            if ($diffMilli !== 0 || $valueDelta !== 0) {
                StockMovement::query()->create([
                    'product_id' => $product->id,
                    'lot_id' => $lot?->id,
                    'type' => 'count_adjust',
                    'weight_kg' => $this->averageCost->formatWeight($diffMilli),
                    'value_delta' => $valueDelta,
                    'ref_type' => 'stock_count',
                    'ref_id' => $count->id,
                    'occurred_at' => $occurredAt,
                    'created_by' => $ownerId,
                    'note' => 'Penyesuaian hitung ulang display',
                ]);
            }

            $this->averageCost->updateLastUnitCost($product);

            return $count->fresh()->load(['product', 'purchase']);
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
            if ($count->status === 'cancelled') {
                throw new LogicException('Hitung ulang ini sudah dibatalkan.');
            }

            $newer = StockCount::query()->where('purchase_id', $count->purchase_id)->where('product_id', $count->product_id)->where('status', 'active')
                ->where(function ($query) use ($count): void {
                    $query->where('counted_at', '>', $count->counted_at)
                        ->orWhere(fn ($nested) => $nested->where('counted_at', $count->counted_at)->where('id', '>', $count->id));
                })->exists();
            if ($newer) {
                throw new LogicException('Hanya hitung ulang terakhir untuk produk ini yang dapat dibatalkan.');
            }

            $movements = StockMovement::query()->where('ref_type', 'stock_count')->where('ref_id', $count->id)->get();
            foreach ($movements as $movement) {
                StockMovement::query()->create([
                    'product_id' => $movement->product_id,
                    'lot_id' => $movement->lot_id,
                    'type' => 'count_reversal',
                    'weight_kg' => $this->averageCost->formatWeight(-$this->averageCost->toMilli((string) $movement->weight_kg)),
                    'value_delta' => -(int) $movement->value_delta,
                    'ref_type' => 'stock_count_cancelled',
                    'ref_id' => $count->id,
                    'occurred_at' => now(),
                    'created_by' => $ownerId,
                    'note' => 'Pembalik pembatalan hitung ulang',
                ]);
            }

            $owner = User::query()->findOrFail($ownerId);
            $count->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancelled_by_name' => $owner->name,
                'cancel_reason' => $reason,
            ]);
            $lot = StockLot::query()->where('purchase_id', $count->purchase_id)->where('product_id', $count->product_id)->first();
            if ($lot !== null) {
                $previousCount = StockCount::query()->where('purchase_id', $count->purchase_id)->where('product_id', $count->product_id)
                    ->where('status', 'active')->orderByDesc('counted_at')->orderByDesc('id')->value('counted_weight_kg');
                $lot->update(['weight_kg' => $previousCount ?? '0.000']);
            }
            AuditLog::query()->create([
                'user_id' => $ownerId,
                'action' => 'stock_count.cancelled',
                'entity_type' => StockCount::class,
                'entity_id' => $count->id,
                'payload' => [
                    'product_id' => $count->product_id,
                    'diff_kg' => $count->diff_kg,
                    'loss_value' => (int) $count->loss_value,
                    'reason' => $reason,
                ],
            ]);

            return $count->fresh()->load(['product', 'purchase']);
        });
    }

    private function lotBalanceMilli(int $lotId): int
    {
        $weight = StockMovement::query()->where('lot_id', $lotId)->sum('weight_kg');

        return $this->averageCost->toMilli((string) $weight);
    }
}
