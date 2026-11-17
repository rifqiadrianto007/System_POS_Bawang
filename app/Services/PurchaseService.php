<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Purchase;
use App\Models\Product;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\Supplier;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

final class PurchaseService
{
    public function __construct(private readonly AverageCostService $averageCost) {}

    public function create(array $attributes, int $ownerId): Purchase
    {
        return DB::transaction(function () use ($attributes, $ownerId): Purchase {
            $date = (string) $attributes['purchased_at'];
            $prefix = 'KLK-'.Carbon::parse($date, 'Asia/Jakarta')->format('ymd').'-';
            $last = Purchase::withTrashed()->where('code', 'like', $prefix.'%')->lockForUpdate()->orderByDesc('code')->value('code');
            $sequence = $last ? ((int) substr($last, -3)) + 1 : 1;
            $purchase = Purchase::query()->create([...$attributes,
                'code' => $prefix.str_pad((string) $sequence, 3, '0', STR_PAD_LEFT),
                'total_cost' => $this->averageCost->totalCost((string) $attributes['gross_weight_kg'], (int) $attributes['price_per_kg']),
                'created_by' => $ownerId,
            ]);
            $this->audit('purchase.created', $purchase, $ownerId, $purchase->only(['code', 'supplier_id', 'jenis', 'gross_weight_kg', 'total_cost']));

            return $this->present($purchase);
        });
    }

    public function list(array $filters): array
    {
        $query = Purchase::query()->with('supplier:id,name')
            ->withSum('stockAdditions as added_raw', 'weight_kg');
        foreach (['from' => '>=', 'to' => '<='] as $key => $operator) {
            if (isset($filters[$key])) {
                $query->where('purchased_at', $operator, $filters[$key]);
            }
        }
        if (isset($filters['jenis'])) {
            $query->where('jenis', $filters['jenis']);
        }

        $all = (clone $query)->get();
        $summary = ['count' => $all->count(), 'gross_weight_kg' => $this->sumWeight($all->pluck('gross_weight_kg')->all()),
            'added_weight_kg' => '0.000', 'remaining_weight_kg' => '0.000', 'unlocked_count' => 0, 'locked_count' => 0];
        $addedTotal = 0;
        $remaining = 0;
        foreach ($all as $purchase) {
            $gross = $this->averageCost->toMilli((string) $purchase->gross_weight_kg);
            $sorted = $this->averageCost->toMilli((string) ($purchase->added_raw ?? '0'));
            $addedTotal += $sorted;
            if ($purchase->sorted_finished_at === null) {
                $remaining += max(0, $gross - $sorted);
                $summary['unlocked_count']++;
            } else {
                $summary['locked_count']++;
            }
        }
        $summary['added_weight_kg'] = $this->averageCost->formatWeight($addedTotal);
        $summary['remaining_weight_kg'] = $this->averageCost->formatWeight($remaining);

        if (isset($filters['status_batch'])) {
            $isLocked = $filters['status_batch'] === 'selesai_disortir';
            $ids = $all->filter(fn (Purchase $purchase): bool => ($purchase->sorted_finished_at !== null) === $isLocked)->modelKeys();
            $query->whereIn('id', $ids);
        }
        $purchases = $query->orderByDesc('purchased_at')->orderByDesc('id')
            ->paginate(min(50, (int) ($filters['per_page'] ?? 20)), ['*'], 'page', (int) ($filters['page'] ?? 1));
        $purchases->getCollection()->transform(fn (Purchase $purchase): Purchase => $this->present($purchase));

        return ['purchases' => $purchases, 'summary' => $summary];
    }

    public function present(Purchase $purchase): Purchase
    {
        $purchase->loadMissing(['supplier:id,name', 'stockAdditions']);
        $gross = $this->averageCost->toMilli((string) ($purchase->gross_weight_kg ?? '0'));
        $sorted = 0;
        if (array_key_exists('added_raw', $purchase->getAttributes())) {
            $sorted = $this->averageCost->toMilli((string) ($purchase->added_raw ?? '0'));
        } else {
            foreach ($purchase->stockAdditions as $addition) {
                $sorted += $this->averageCost->toMilli((string) $addition->weight_kg);
            }
        }
        $remaining = $purchase->sorted_finished_at === null ? max(0, $gross - $sorted) : 0;
        $purchase->setAttribute('added_weight_kg', $this->averageCost->formatWeight($sorted));
        $purchase->setAttribute('remaining_weight_kg', $this->averageCost->formatWeight($remaining));
        $purchase->setAttribute('status_batch', $purchase->sorted_finished_at !== null ? 'selesai_disortir' : 'belum_selesai');
        $hasStockHistory = $purchase->stockLots()->exists()
            || DB::table('stock_additions')->where('purchase_id', $purchase->id)->exists();
        $purchase->setAttribute('can_edit', ! $hasStockHistory && $purchase->sorted_finished_at === null);
        $purchase->setAttribute('can_delete', ! $hasStockHistory && $purchase->sorted_finished_at === null);
        $purchase->setAttribute('is_locked', $purchase->sorted_finished_at !== null);
        $purchase->setAttribute('needs_stock_reconciliation', (bool) $purchase->needs_stock_reconciliation);
        $purchase->setAttribute('cleaning_shrink_kg', (string) ($purchase->cleaning_shrink_kg ?? '0.000'));
        $purchase->setAttribute('cleaning_loss_value', (int) ($purchase->cleaning_loss_value ?? 0));
        $purchase->setAttribute('is_locked', $purchase->sorted_finished_at !== null);

        return $purchase;
    }

    /** @param Collection<int,Purchase> $purchases @return Collection<int,Purchase> */
    public function deriveMetrics(Collection $purchases): Collection
    {
        $purchases->loadMissing(['supplier:id,name']);

        return $purchases->map(fn (Purchase $purchase): Purchase => $this->present($purchase));
    }

    public function update(Purchase $purchase, array $attributes, int $ownerId): Purchase
    {
        return DB::transaction(function () use ($purchase, $attributes, $ownerId): Purchase {
            $purchase = Purchase::query()->with('stockLots')->lockForUpdate()->findOrFail($purchase->id);
            if ($purchase->sorted_finished_at !== null) {
                throw new LogicException('Batch yang sudah selesai disortir tidak dapat diubah.');
            }
            $hasStockHistory = $purchase->stockLots->isNotEmpty()
                || DB::table('stock_additions')->where('purchase_id', $purchase->id)->exists();
            if ($hasStockHistory && array_diff(array_keys($attributes), ['notes', 'supplier_id']) !== []) {
                throw new LogicException('Kulak sudah memiliki data hitung ulang; hanya catatan dan pemasok yang dapat diubah.');
            }
            $before = $purchase->only(array_keys($attributes));
            $purchase->fill($attributes);
            if (array_key_exists('gross_weight_kg', $attributes) || array_key_exists('price_per_kg', $attributes)) {
                $purchase->total_cost = $this->averageCost->totalCost((string) $purchase->gross_weight_kg, (int) $purchase->price_per_kg);
            }
            $purchase->save();
            $this->audit('purchase.updated', $purchase, $ownerId, ['before' => $before, 'after' => $attributes]);

            return $this->present($purchase->fresh());
        });
    }

    public function updateSupplier(Supplier $supplier, array $attributes, int $ownerId): Supplier
    {
        return DB::transaction(function () use ($supplier, $attributes, $ownerId): Supplier {
            $supplier = Supplier::query()->lockForUpdate()->findOrFail($supplier->id);
            $before = $supplier->only(array_keys($attributes));
            $supplier->update($attributes);
            $this->audit('supplier.updated', $supplier, $ownerId, ['before' => $before, 'after' => $attributes]);

            return $supplier->fresh();
        });
    }

    public function delete(Purchase $purchase, int $ownerId): void
    {
        DB::transaction(function () use ($purchase, $ownerId): void {
            $p = Purchase::query()->with('stockLots')->lockForUpdate()->findOrFail($purchase->id);
            if ($p->stockLots->isNotEmpty() || DB::table('stock_additions')->where('purchase_id', $p->id)->exists()) {
                throw new LogicException('Kulak yang sudah memiliki hitung ulang tidak dapat dihapus.');
            }
            if ($p->sorted_finished_at !== null) {
                throw new LogicException('Batch yang sudah selesai disortir tidak dapat dihapus.');
            }
            $this->audit('purchase.deleted', $p, $ownerId, $p->only(['code', 'supplier_id', 'purchased_at', 'gross_weight_kg', 'jenis', 'total_cost']));
            $p->delete();
        });
    }

    public function finishSorting(Purchase $purchase, int $ownerId): Purchase
    {
        return DB::transaction(function () use ($purchase, $ownerId): Purchase {
            $purchase = Purchase::query()->lockForUpdate()->findOrFail($purchase->id);
            if ($purchase->sorted_finished_at !== null) {
                throw new LogicException('Batch ini sudah selesai disortir dan tidak dapat dibatalkan.');
            }
            $added = (string) DB::table('stock_additions')->where('purchase_id', $purchase->id)->sum('weight_kg');
            $grossMilli = $this->averageCost->toMilli((string) $purchase->gross_weight_kg);
            $addedMilli = $this->averageCost->toMilli($added);
            if ($purchase->needs_stock_reconciliation && $addedMilli === 0) {
                throw new LogicException('Riwayat stok batch lama perlu direkonsiliasi sebelum batch dapat diselesaikan.');
            }
            if ($addedMilli > $grossMilli) {
                throw new LogicException('Total berat Tambah melebihi berat kulak batch.');
            }

            $remainingMilli = $grossMilli - $addedMilli;
            $lossValue = $this->averageCost->valueForWeight($remainingMilli, (int) $purchase->price_per_kg);
            $finishedAt = now();
            $wasNeedsReconciliation = (bool) $purchase->needs_stock_reconciliation;
            $purchase->forceFill([
                'sorted_finished_at' => $finishedAt,
                'sorted_finished_by' => $ownerId,
                'cleaning_shrink_kg' => $this->averageCost->formatWeight($remainingMilli),
                'cleaning_loss_value' => $lossValue,
                'needs_stock_reconciliation' => false,
            ])->save();

            AuditLog::query()->create([
                'user_id' => $ownerId,
                'action' => 'purchase.sorting_finished',
                'entity_type' => Purchase::class,
                'entity_id' => $purchase->id,
                'payload' => [
                    'purchase_id' => $purchase->id,
                    'cleaning_shrink_kg' => $this->averageCost->formatWeight($remainingMilli),
                    'cleaning_loss_value' => $lossValue,
                    'finished_at' => $finishedAt->toIso8601String(),
                    'reconciled_legacy_history' => $wasNeedsReconciliation,
                ],
            ]);

            return $this->present($purchase->fresh());
        });
    }

    private function sumWeight(array $weights): string
    {
        return $this->averageCost->formatWeight(array_sum(array_map(fn ($weight) => $this->averageCost->toMilli((string) $weight), $weights)));
    }

    private function audit(string $action, object $entity, int $userId, array $payload): void
    {
        AuditLog::query()->create(['user_id' => $userId, 'action' => $action, 'entity_type' => $entity::class, 'entity_id' => $entity->id, 'payload' => $payload]);
    }
}
