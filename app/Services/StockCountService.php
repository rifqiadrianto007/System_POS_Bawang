<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Product;
use App\Models\StockCount;
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
            $occurredAt = Carbon::parse((string) $attributes['counted_at'])->utc();
            $balance = $this->averageCost->stockAt($product, $occurredAt);
            $systemMilli = $this->averageCost->toMilli($balance['stock_kg']);
            $countedMilli = $this->averageCost->toMilli((string) $attributes['counted_weight_kg']);
            $diffMilli = $countedMilli - $systemMilli;
            $unitCost = (int) ($balance['avg_cost'] ?? $product->last_unit_cost ?? 0);
            $adjustmentValue = $diffMilli === 0
                ? 0
                : $this->averageCost->valueForWeight(abs($diffMilli), $unitCost);
            $valueDelta = $diffMilli < 0 ? -$adjustmentValue : $adjustmentValue;
            $lossValue = $diffMilli < 0 ? $adjustmentValue : 0;
            $surplusMilli = max(0, $diffMilli);
            $surplusValue = $diffMilli > 0 ? $adjustmentValue : 0;

            $count = StockCount::query()->create([
                'product_id' => $product->id,
                'purchase_id' => null,
                'counted_weight_kg' => $this->averageCost->formatWeight($countedMilli),
                'system_weight_kg' => $this->averageCost->formatWeight($systemMilli),
                'diff_kg' => $this->averageCost->formatWeight($diffMilli),
                'unit_cost_avg' => $unitCost,
                'loss_value' => $lossValue,
                'surplus_kg' => $this->averageCost->formatWeight($surplusMilli),
                'surplus_value' => $surplusValue,
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
                    'purchase_id' => null,
                    'counted_weight_kg' => $this->averageCost->formatWeight($countedMilli),
                    'system_weight_kg' => $this->averageCost->formatWeight($systemMilli),
                    'diff_kg' => $this->averageCost->formatWeight($diffMilli),
                    'loss_value' => $lossValue,
                    'surplus_kg' => $this->averageCost->formatWeight($surplusMilli),
                    'surplus_value' => $surplusValue,
                ],
            ]);

            if ($diffMilli !== 0 || $valueDelta !== 0) {
                StockMovement::query()->create([
                    'product_id' => $product->id,
                    'lot_id' => null,
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

            $newer = StockCount::query()->where('product_id', $count->product_id)->where('status', 'active')
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
            AuditLog::query()->create([
                'user_id' => $ownerId,
                'action' => 'stock_count.cancelled',
                'entity_type' => StockCount::class,
                'entity_id' => $count->id,
                'payload' => [
                    'product_id' => $count->product_id,
                    'diff_kg' => $count->diff_kg,
                    'loss_value' => (int) $count->loss_value,
                    'surplus_kg' => (string) $count->surplus_kg,
                    'surplus_value' => (int) $count->surplus_value,
                    'reason' => $reason,
                ],
            ]);

            return $count->fresh()->load(['product', 'purchase']);
        });
    }

}
