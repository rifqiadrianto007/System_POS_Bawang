<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Purchase;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\Supplier;
use Illuminate\Support\Facades\DB;
use LogicException;

final class PurchaseService
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes, int $ownerId): Purchase
    {
        return DB::transaction(function () use ($attributes, $ownerId): Purchase {
            $purchase = Purchase::query()->create([
                ...$attributes,
                'total_cost' => $this->calculateTotalCost(
                    (string) $attributes['gross_weight_kg'],
                    (int) $attributes['price_per_kg']
                ),
                'status' => 'open',
                'created_by' => $ownerId,
            ]);

            $this->recordAudit('purchase.created', Purchase::class, $purchase->id, $ownerId, [
                'supplier_id' => $purchase->supplier_id,
                'total_cost' => $purchase->total_cost,
            ]);

            return $purchase->load('supplier');
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateSupplier(Supplier $supplier, array $attributes, int $ownerId): Supplier
    {
        return DB::transaction(function () use ($supplier, $attributes, $ownerId): Supplier {
            $supplier->update($attributes);
            $this->recordAudit('supplier.updated', Supplier::class, $supplier->id, $ownerId, $attributes);

            return $supplier;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function addLot(Purchase $purchase, array $attributes, int $ownerId): StockLot
    {
        return DB::transaction(function () use ($purchase, $attributes, $ownerId): StockLot {
            $purchase = Purchase::query()->lockForUpdate()->findOrFail($purchase->id);

            if ($purchase->status !== 'open') {
                throw new LogicException('Lot tidak dapat diubah setelah pembelian difinalkan.');
            }

            $lot = $purchase->stockLots()->create($attributes);
            $this->recordAudit('purchase.lot_added', StockLot::class, $lot->id, $ownerId, [
                'purchase_id' => $purchase->id,
                'product_id' => $lot->product_id,
                'clean_weight_kg' => $lot->clean_weight_kg,
            ]);

            return $lot->load('product');
        });
    }

    public function deleteLot(Purchase $purchase, StockLot $lot, int $ownerId): void
    {
        DB::transaction(function () use ($purchase, $lot, $ownerId): void {
            $purchase = Purchase::query()->lockForUpdate()->findOrFail($purchase->id);

            if ($purchase->status !== 'open' || $lot->purchase_id !== $purchase->id) {
                throw new LogicException('Lot tidak dapat dihapus setelah pembelian difinalkan.');
            }

            $lot->delete();
            $this->recordAudit('purchase.lot_deleted', StockLot::class, $lot->id, $ownerId, [
                'purchase_id' => $purchase->id,
            ]);
        });
    }

    public function finalize(Purchase $purchase, int $ownerId): Purchase
    {
        return DB::transaction(function () use ($purchase, $ownerId): Purchase {
            $purchase = Purchase::query()->lockForUpdate()->with('stockLots')->findOrFail($purchase->id);

            if ($purchase->status !== 'open') {
                throw new LogicException('Pembelian sudah difinalkan.');
            }

            if ($purchase->stockLots->isEmpty()) {
                throw new LogicException('Pembelian belum memiliki lot.');
            }

            $cleanWeightMilli = $purchase->stockLots->sum(
                fn (StockLot $lot): int => $this->toMilliKilograms((string) $lot->clean_weight_kg)
            );
            $costPerCleanKg = $this->roundHalfUp(
                ((int) $purchase->total_cost * 1000),
                $cleanWeightMilli
            );
            $now = now();

            foreach ($purchase->stockLots as $lot) {
                $lot->update(['unit_cost' => $costPerCleanKg]);
                StockMovement::query()->create([
                    'product_id' => $lot->product_id,
                    'lot_id' => $lot->id,
                    'type' => 'in_clean',
                    'weight_kg' => $lot->clean_weight_kg,
                    'unit_cost' => $costPerCleanKg,
                    'ref_type' => Purchase::class,
                    'ref_id' => $purchase->id,
                    'occurred_at' => $now,
                    'created_by' => $ownerId,
                    'note' => 'Stok bersih dari pembelian final',
                ]);
            }

            $purchase->update([
                'status' => 'finalized',
                'cost_per_clean_kg' => $costPerCleanKg,
                'clean_weight_kg' => $cleanWeightMilli / 1000,
                'finalized_at' => $now,
            ]);
            $this->recordAudit('purchase.finalized', Purchase::class, $purchase->id, $ownerId, [
                'cost_per_clean_kg' => $costPerCleanKg,
                'clean_weight_kg' => $cleanWeightMilli / 1000,
            ]);

            return $purchase->load(['supplier', 'stockLots.product']);
        });
    }

    private function calculateTotalCost(string $weight, int $pricePerKg): int
    {
        return $this->roundHalfUp($this->toMilliKilograms($weight) * $pricePerKg, 1000);
    }

    private function toMilliKilograms(string $weight): int
    {
        $normalized = ltrim($weight, '+');
        [$whole, $fraction] = array_pad(explode('.', $normalized, 2), 2, '');

        return ((int) $whole * 1000) + (int) str_pad(substr($fraction, 0, 3), 3, '0');
    }

    private function roundHalfUp(int $numerator, int $denominator): int
    {
        return intdiv($numerator * 2 + $denominator, $denominator * 2);
    }

    private function recordAudit(
        string $action,
        string $entityType,
        int $entityId,
        int $userId,
        array $payload
    ): void {
        AuditLog::query()->create([
            'user_id' => $userId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'payload' => $payload,
        ]);
    }
}
