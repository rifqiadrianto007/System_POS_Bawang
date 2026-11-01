<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Purchase;
use App\Models\SaleAllocation;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use LogicException;
use Illuminate\Validation\ValidationException;

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

            return $this->prepareResponse($purchase);
        });
    }

    /** @param array<string, mixed> $filters
     *  @return array{purchases: LengthAwarePaginator, summary: array{count:int,open_count:int,finalized_count:int,gross_weight_kg:string,open_gross_weight_kg:string}}
     */
    public function list(array $filters): array
    {
        $dateQuery = Purchase::query();
        if (isset($filters['from'])) {
            $dateQuery->where('purchased_at', '>=', $filters['from']);
        }
        if (isset($filters['to'])) {
            $dateQuery->where('purchased_at', '<=', $filters['to']);
        }

        $aggregate = (clone $dateQuery)->selectRaw(
            'COUNT(*) as count, SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as open_count, SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as finalized_count, SUM(gross_weight_kg) as gross_weight_kg, SUM(CASE WHEN status = ? THEN gross_weight_kg ELSE 0 END) as open_gross_weight_kg',
            ['open', 'finalized', 'open']
        )->first();
        $summary = [
            'count' => (int) $aggregate->count,
            'open_count' => (int) $aggregate->open_count,
            'finalized_count' => (int) $aggregate->finalized_count,
            'gross_weight_kg' => $this->formatWeight($this->toMilliKilograms((string) ($aggregate->gross_weight_kg ?? '0'))),
            'open_gross_weight_kg' => $this->formatWeight($this->toMilliKilograms((string) ($aggregate->open_gross_weight_kg ?? '0'))),
        ];

        $query = Purchase::query()->with('supplier:id,name')
            ->withCount('stockLots as lots_count')
            ->withSum('stockLots as lots_total_raw', 'clean_weight_kg');
        if (isset($filters['from'])) {
            $query->where('purchased_at', '>=', $filters['from']);
        }
        if (isset($filters['to'])) {
            $query->where('purchased_at', '<=', $filters['to']);
        }
        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (($filters['include'] ?? null) === 'lots') {
            $query->with('stockLots.product');
        }

        $perPage = min(50, (int) ($filters['per_page'] ?? 20));
        $purchases = $query->orderByDesc('purchased_at')->orderByDesc('id')
            ->paginate($perPage, ['*'], 'page', (int) ($filters['page'] ?? 1));

        $purchases->getCollection()->each(function (Purchase $purchase): void {
            $grossMilli = $this->toMilliKilograms((string) $purchase->gross_weight_kg);
            $lotsMilli = $this->toMilliKilograms((string) ($purchase->lots_total_raw ?? '0'));
            $purchase->setAttribute('lots_total_kg', $this->formatWeight($lotsMilli));
            $purchase->setAttribute('cleaning_shrink_kg', $purchase->status === 'finalized'
                ? $this->formatWeight($grossMilli - $lotsMilli)
                : null);
            $purchase->setAttribute('editable', $purchase->status === 'open');
            if ($purchase->status === 'open') {
                $purchase->setAttribute('cost_per_clean_kg', null);
            }
        });

        return ['purchases' => $purchases, 'summary' => $summary];
    }

    public function present(Purchase $purchase): Purchase
    {
        return $this->prepareResponse($purchase);
    }

    /** @param array<string, mixed> $attributes */
    public function update(Purchase $purchase, array $attributes, int $ownerId): Purchase
    {
        return DB::transaction(function () use ($purchase, $attributes, $ownerId): Purchase {
            $purchase = Purchase::query()->lockForUpdate()->with('stockLots')->findOrFail($purchase->id);

            if ($purchase->status === 'finalized' && array_diff(array_keys($attributes), ['notes']) !== []) {
                throw new LogicException('Kulak yang sudah final tidak dapat diubah.');
            }

            $grossWeight = (string) ($attributes['gross_weight_kg'] ?? $purchase->gross_weight_kg);
            if ($this->lotsTotalMilli($purchase->stockLots) > $this->toMilliKilograms($grossWeight)) {
                throw ValidationException::withMessages([
                    'gross_weight_kg' => 'Jumlah berat lot tidak boleh melebihi berat kotor kulak.',
                ]);
            }

            $before = $purchase->only(['supplier_id', 'purchased_at', 'gross_weight_kg', 'price_per_kg', 'total_cost', 'notes']);
            $values = $attributes;
            if ($purchase->status === 'open') {
                $price = (int) ($attributes['price_per_kg'] ?? $purchase->price_per_kg);
                $values['total_cost'] = $this->calculateTotalCost($grossWeight, $price);
            }
            $purchase->update($values);

            $this->recordAudit('purchase.updated', Purchase::class, $purchase->id, $ownerId, [
                'before' => $before,
                'after' => $purchase->fresh()->only(['supplier_id', 'purchased_at', 'gross_weight_kg', 'price_per_kg', 'total_cost', 'notes']),
            ]);

            return $this->prepareResponse($purchase->fresh());
        });
    }

    public function delete(Purchase $purchase, int $ownerId): void
    {
        DB::transaction(function () use ($purchase, $ownerId): void {
            $purchase = Purchase::query()->lockForUpdate()->with('stockLots')->findOrFail($purchase->id);
            if ($purchase->status !== 'open') {
                throw new LogicException('Kulak yang sudah final tidak dapat diubah.');
            }

            $payload = $purchase->only(['supplier_id', 'purchased_at', 'gross_weight_kg', 'price_per_kg', 'total_cost', 'notes']);
            $this->recordAudit('purchase.deleted', Purchase::class, $purchase->id, $ownerId, $payload);
            $purchase->stockLots()->delete();
            $purchase->delete();
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
                throw new LogicException('Kulak yang sudah final tidak dapat diubah.');
            }

            $this->assertLotTotalWithinGross($purchase, (string) $attributes['clean_weight_kg']);

            $lot = $purchase->stockLots()->create($attributes);
            $this->recordAudit('purchase.lot_added', StockLot::class, $lot->id, $ownerId, [
                'purchase_id' => $purchase->id,
                'product_id' => $lot->product_id,
                'clean_weight_kg' => $lot->clean_weight_kg,
            ]);

            return $lot->load('product');
        });
    }

    /** @param array<string, mixed> $attributes */
    public function updateLot(Purchase $purchase, StockLot $lot, array $attributes, int $ownerId): StockLot
    {
        return DB::transaction(function () use ($purchase, $lot, $attributes, $ownerId): StockLot {
            $purchase = Purchase::query()->lockForUpdate()->findOrFail($purchase->id);
            $lot = StockLot::query()->lockForUpdate()->findOrFail($lot->id);
            if ($lot->purchase_id !== $purchase->id) {
                throw (new ModelNotFoundException())->setModel(StockLot::class, [$lot->id]);
            }
            if ($purchase->status !== 'open') {
                throw new LogicException('Kulak yang sudah final tidak dapat diubah.');
            }

            $this->assertLotTotalWithinGross($purchase, (string) $attributes['clean_weight_kg'], $lot->id);
            $before = $lot->only(['product_id', 'clean_weight_kg', 'cleaned_at', 'notes']);
            $lot->update($attributes);
            $this->recordAudit('purchase.lot_updated', StockLot::class, $lot->id, $ownerId, [
                'purchase_id' => $purchase->id,
                'before' => $before,
                'after' => $lot->fresh()->only(['product_id', 'clean_weight_kg', 'cleaned_at', 'notes']),
            ]);

            return $lot->load('product');
        });
    }

    public function deleteLot(Purchase $purchase, StockLot $lot, int $ownerId): void
    {
        DB::transaction(function () use ($purchase, $lot, $ownerId): void {
            $purchase = Purchase::query()->lockForUpdate()->findOrFail($purchase->id);

            if ($lot->purchase_id !== $purchase->id) {
                throw (new ModelNotFoundException())->setModel(StockLot::class, [$lot->id]);
            }
            if ($purchase->status !== 'open') {
                throw new LogicException('Kulak yang sudah final tidak dapat diubah.');
            }
            if (StockMovement::query()->where('lot_id', $lot->id)->exists()
                || SaleAllocation::query()->where('lot_id', $lot->id)->exists()) {
                throw new LogicException('Kulak yang sudah final tidak dapat diubah.');
            }

            try {
                $lot->delete();
            } catch (QueryException $exception) {
                if ($exception->getCode() !== '23000') {
                    throw $exception;
                }

                throw new LogicException('Kulak yang sudah final tidak dapat diubah.', previous: $exception);
            }
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
                throw new LogicException('Kulak yang sudah final tidak dapat diubah.');
            }

            if ($purchase->stockLots->isEmpty()) {
                throw new LogicException('Kulak yang sudah final tidak dapat diubah.');
            }

            $cleanWeightMilli = $purchase->stockLots->sum(
                fn (StockLot $lot): int => $this->toMilliKilograms((string) $lot->clean_weight_kg)
            );
            if ($cleanWeightMilli > $this->toMilliKilograms((string) $purchase->gross_weight_kg)) {
                throw ValidationException::withMessages([
                    'gross_weight_kg' => 'Jumlah berat lot tidak boleh melebihi berat kotor kulak.',
                ]);
            }
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
            $shortfallResolved = app(ShortfallResolver::class)->resolveLots($purchase->stockLots, $ownerId, $now);
            $this->recordAudit('purchase.finalized', Purchase::class, $purchase->id, $ownerId, [
                'cost_per_clean_kg' => $costPerCleanKg,
                'clean_weight_kg' => $cleanWeightMilli / 1000,
                'shortfall_resolved' => $shortfallResolved,
            ]);

            $purchase->setAttribute('shortfall_resolved', $shortfallResolved);

            return $this->prepareResponse($purchase->load(['supplier', 'stockLots.product']));
        });
    }

    private function assertLotTotalWithinGross(Purchase $purchase, string $newWeight, ?int $excludeLotId = null): void
    {
        $lots = $purchase->stockLots()->when($excludeLotId !== null, fn ($query) => $query->whereKeyNot($excludeLotId))->get();
        if ($this->lotsTotalMilli($lots) + $this->toMilliKilograms($newWeight) > $this->toMilliKilograms((string) $purchase->gross_weight_kg)) {
            throw ValidationException::withMessages([
                'clean_weight_kg' => 'Jumlah berat lot tidak boleh melebihi berat kotor kulak.',
            ]);
        }
    }

    /** @param iterable<StockLot> $lots */
    private function lotsTotalMilli(iterable $lots): int
    {
        $total = 0;
        foreach ($lots as $lot) {
            $total += $this->toMilliKilograms((string) $lot->clean_weight_kg);
        }

        return $total;
    }

    private function prepareResponse(Purchase $purchase): Purchase
    {
        $purchase->loadMissing(['supplier', 'stockLots.product']);
        $lotsTotal = $this->lotsTotalMilli($purchase->stockLots);
        $gross = $this->toMilliKilograms((string) $purchase->gross_weight_kg);
        $purchase->setAttribute('editable', $purchase->status === 'open');
        $purchase->setAttribute('lots_total_kg', $this->formatWeight($lotsTotal));
        $purchase->setAttribute(
            'cleaning_shrink_kg',
            $purchase->status === 'finalized' ? $this->formatWeight($gross - $lotsTotal) : null
        );
        if ($purchase->status === 'open') {
            $purchase->setAttribute('cost_per_clean_kg', null);
        }

        return $purchase;
    }

    private function formatWeight(int $milliKilograms): string
    {
        $sign = $milliKilograms < 0 ? '-' : '';
        $absolute = abs($milliKilograms);

        return $sign.intdiv($absolute, 1000).'.'.str_pad((string) ($absolute % 1000), 3, '0', STR_PAD_LEFT);
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
