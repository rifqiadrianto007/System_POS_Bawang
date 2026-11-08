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
            $balance = $this->averageCost->stock($product);
            $systemMilli = $this->averageCost->toMilli($balance['stock_kg']);
            $countedMilli = $this->averageCost->toMilli((string) $attributes['counted_weight_kg']);
            $diffMilli = $countedMilli - $systemMilli;
            $occurredAt = Carbon::parse((string) $attributes['counted_at']);
            $avgCost = $balance['avg_cost'] ?? (int) ($product->last_unit_cost ?? 0);
            $lossValue = 0;
            $valueDelta = 0;

            if ($systemMilli > 0) {
                if ($diffMilli < 0) {
                    $lossValue = $this->averageCost->valueForWeight(abs($diffMilli), $avgCost);
                    $valueDelta = -$lossValue;
                } elseif ($diffMilli > 0) {
                    $lossValue = -$this->averageCost->valueForWeight($diffMilli, $avgCost);
                    $valueDelta = -$lossValue;
                }
            } else {
                $targetValue = $this->averageCost->valueForWeight($countedMilli, (int) ($product->last_unit_cost ?? 0));
                $valueDelta = $targetValue - $balance['stock_value'];
                $lossValue = 0;
            }

            $count = StockCount::query()->create([
                'product_id' => $product->id,
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
                    'counted_weight_kg' => $this->averageCost->formatWeight($countedMilli),
                    'system_weight_kg' => $this->averageCost->formatWeight($systemMilli),
                    'diff_kg' => $this->averageCost->formatWeight($diffMilli),
                    'loss_value' => $lossValue,
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
                    'lot_id' => null,
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
                    'reason' => $reason,
                ],
            ]);

            return $count->fresh()->load('product');
        });
    }
}
