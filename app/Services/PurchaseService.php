<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\TransactionItem;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
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
            $this->audit('purchase.created', $purchase, $ownerId, $purchase->only(['code', 'supplier_id', 'jenis', 'total_cost']));

            return $this->present($purchase);
        });
    }

    public function list(array $filters): array
    {
        $base = Purchase::query()->with(['supplier:id,name', 'stockLots.product'])->withSum('stockLots as sorted_raw', 'weight_kg');
        foreach (['from' => '>=', 'to' => '<='] as $key => $operator) {
            if (isset($filters[$key])) {
                $base->where('purchased_at', $operator, $filters[$key]);
            }
        }
        if (isset($filters['jenis'])) {
            $base->where('jenis', $filters['jenis']);
        }
        $all = (clone $base)->get();
        $this->attachSoldMetrics($all);
        $summary = ['count' => $all->count(), 'unsorted_count' => 0, 'sorting_count' => 0, 'closed_count' => 0,
            'gross_weight_kg' => $this->sumWeight($all->pluck('gross_weight_kg')->all()), 'warehouse_kg' => '0.000'];
        $warehouse = 0;
        foreach ($all as $purchase) {
            $state = $this->sortingStatus($purchase);
            $summary[$state.'_count']++;
            if (! $purchase->closed_at) {
                $warehouse += max(0, $this->averageCost->toMilli((string) $purchase->gross_weight_kg) - $this->averageCost->toMilli((string) $purchase->sorted_raw));
            }
        }
        $summary['warehouse_kg'] = $this->averageCost->formatWeight($warehouse);
        $query = (clone $base)->withCount('stockLots');
        if (($filters['sorting'] ?? null) !== null) {
            $query->getQuery()->whereIn('id', (clone $base)->get()->filter(fn (Purchase $p) => $this->sortingStatus($p) === $filters['sorting'])->pluck('id'));
        }
        if (($filters['include'] ?? null) === 'lots') {
            $query->with('stockLots.product');
        }
        $purchases = $query->orderByDesc('purchased_at')->orderByDesc('id')->paginate(min(50, (int) ($filters['per_page'] ?? 20)), ['*'], 'page', (int) ($filters['page'] ?? 1));
        $this->attachSoldMetrics($purchases->getCollection());
        $includeLots = ($filters['include'] ?? null) === 'lots';
        $purchases->getCollection()->transform(function (Purchase $purchase) use ($includeLots): Purchase {
            $purchase = $this->present($purchase);
            $purchase->setAttribute('include_lots', $includeLots);

            return $purchase;
        });

        return ['purchases' => $purchases, 'summary' => $summary];
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

    public function present(Purchase $purchase): Purchase
    {
        $purchase->loadMissing(['supplier:id,name', 'stockLots.product']);
        $sorted = (int) $purchase->stockLots->sum(fn (StockLot $lot) => $this->averageCost->toMilli((string) $lot->weight_kg));
        $gross = $this->averageCost->toMilli((string) $purchase->gross_weight_kg);
        $unsorted = max(0, $gross - $sorted);
        $purchase->setAttribute('sorted_kg', $this->averageCost->formatWeight($sorted));
        $purchase->setAttribute('unsorted_kg', $this->averageCost->formatWeight($unsorted));
        $purchase->setAttribute('sorting_status', $purchase->closed_at ? 'closed' : ($sorted > 0 ? 'sorting' : 'unsorted'));
        $sold = (int) ($purchase->getAttribute('_sold_milli') ?? $this->soldForPurchase($purchase));
        $purchase->setAttribute('susut_kg', $this->averageCost->formatWeight(max(0, $gross - $sold)));
        $purchase->setAttribute('sold_kg', $this->averageCost->formatWeight($sold));
        $purchase->setAttribute('susut_final', $purchase->closed_at !== null);
        $purchase->setAttribute('can_edit', ! $purchase->closed_at && $sorted === 0);
        $purchase->setAttribute('can_delete', $purchase->stockLots->isEmpty());
        $purchase->setAttribute('can_add_lot', ! $purchase->closed_at);
        $purchase->setAttribute('lots', $purchase->stockLots->map(fn (StockLot $lot) => [
            'id' => $lot->id, 'product_id' => $lot->product_id, 'product_name' => $lot->product->name,
            'weight_kg' => (string) $lot->weight_kg, 'sorted_at' => $lot->sorted_at?->format('Y-m-d'), 'unit_cost' => (int) $lot->unit_cost,
        ])->values()->all());

        return $purchase;
    }

    /** @param Collection<int, Purchase> $purchases @return Collection<int, Purchase> */
    public function deriveMetrics(Collection $purchases): Collection
    {
        $purchases->loadMissing(['supplier:id,name', 'stockLots.product']);
        $this->attachSoldMetrics($purchases);

        return $purchases->map(fn (Purchase $purchase): Purchase => $this->present($purchase));
    }

    public function update(Purchase $purchase, array $attributes, int $ownerId): Purchase
    {
        return DB::transaction(function () use ($purchase, $attributes, $ownerId): Purchase {
            $purchase = Purchase::query()->with('stockLots')->lockForUpdate()->findOrFail($purchase->id);
            if ($purchase->closed_at) {
                throw new LogicException('Kulak tertutup tidak dapat diubah.');
            }
            if ($purchase->stockLots->isNotEmpty() && array_diff(array_keys($attributes), ['notes', 'supplier_id']) !== []) {
                throw new LogicException('Kulak sudah memiliki bawang bersih; hapus dulu bawang bersihnya untuk mengubah data ini.');
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

    public function delete(Purchase $purchase, int $ownerId): void
    {
        DB::transaction(function () use ($purchase, $ownerId): void {
            $p = Purchase::query()->with('stockLots')->lockForUpdate()->findOrFail($purchase->id);
            if ($p->closed_at || $p->stockLots->isNotEmpty()) {
                throw new LogicException('Kulak yang memiliki bawang bersih atau tertutup tidak dapat dihapus.');
            }
            $this->audit('purchase.deleted', $p, $ownerId, $p->only(['code', 'supplier_id', 'purchased_at', 'gross_weight_kg', 'jenis', 'total_cost']));
            $p->delete();
        });
    }

    public function addLot(Purchase $purchase, array $attributes, int $ownerId): StockLot
    {
        return DB::transaction(function () use ($purchase, $attributes, $ownerId): StockLot {
            $p = Purchase::query()->lockForUpdate()->findOrFail($purchase->id);
            if ($p->closed_at) {
                throw new LogicException('Kulak sudah ditutup.');
            }
            $product = Product::query()->lockForUpdate()->findOrFail($attributes['product_id']);
            if ($product->jenis !== $p->jenis) {
                throw ValidationException::withMessages(['product_id' => 'Jenis barang harus sama dengan jenis kulak.']);
            }
            $current = 0;
            foreach (StockLot::query()->where('purchase_id', $p->id)->get(['weight_kg']) as $existingLot) {
                $current += $this->averageCost->toMilli((string) $existingLot->weight_kg);
            }
            $new = $this->averageCost->toMilli((string) $attributes['weight_kg']);
            if ($current + $new > $this->averageCost->toMilli((string) $p->gross_weight_kg)) {
                throw ValidationException::withMessages(['weight_kg' => 'Jumlah berat bawang bersih tidak boleh melebihi berat kotor kulak.']);
            }
            $lot = $p->stockLots()->create([...$attributes, 'unit_cost' => (int) $p->price_per_kg, 'created_by' => $ownerId]);
            $value = $this->averageCost->valueForWeight($new, (int) $p->price_per_kg);
            StockMovement::query()->create(['product_id' => $product->id, 'lot_id' => $lot->id, 'type' => 'in_sort', 'weight_kg' => $lot->weight_kg, 'value_delta' => $value, 'ref_type' => Purchase::class, 'ref_id' => $p->id, 'occurred_at' => now(), 'created_by' => $ownerId, 'note' => 'Bawang bersih hasil sortir']);
            $this->averageCost->updateLastUnitCost($product);
            $this->audit('purchase.lot_added', $lot, $ownerId, ['purchase_id' => $p->id, 'product_id' => $product->id, 'weight_kg' => $lot->weight_kg]);

            return $lot->load('product');
        });
    }

    public function deleteLot(Purchase $purchase, StockLot $lot, int $ownerId): void
    {
        DB::transaction(function () use ($purchase, $lot, $ownerId): void {
            $p = Purchase::query()->lockForUpdate()->findOrFail($purchase->id);
            $l = StockLot::query()->lockForUpdate()->findOrFail($lot->id);
            if ($l->purchase_id !== $p->id) {
                throw (new ModelNotFoundException)->setModel(StockLot::class, [$l->id]);
            }
            if ($p->closed_at) {
                throw new LogicException('Kulak sudah ditutup.');
            }
            $stock = $this->averageCost->stock($l->product_id);
            $weight = $this->averageCost->toMilli((string) $l->weight_kg);
            if ($this->averageCost->toMilli($stock['stock_kg']) - $weight < 0) {
                throw new LogicException('Stok barang ini sudah terjual; gunakan hitung ulang untuk koreksi.');
            }
            $in = StockMovement::query()->where('lot_id', $l->id)->where('type', 'in_sort')->firstOrFail();
            StockMovement::query()->create(['product_id' => $l->product_id, 'lot_id' => $l->id, 'type' => 'in_sort_reversal', 'weight_kg' => $this->averageCost->formatWeight(-$weight), 'value_delta' => -(int) $in->value_delta, 'ref_type' => Purchase::class, 'ref_id' => $p->id, 'occurred_at' => now(), 'created_by' => $ownerId, 'note' => 'Pembalik sortir']);
            $l->delete();
            $this->averageCost->updateLastUnitCost($l->product);
            $this->audit('purchase.lot_removed', $l, $ownerId, ['purchase_id' => $p->id, 'weight_kg' => $l->weight_kg, 'value_delta' => -(int) $in->value_delta]);
        });
    }

    public function close(Purchase $purchase, int $ownerId): Purchase
    {
        return DB::transaction(function () use ($purchase, $ownerId): Purchase {
            $p = Purchase::query()->with('stockLots')->lockForUpdate()->findOrFail($purchase->id);
            if ($p->stockLots->isEmpty()) {
                throw new LogicException('Kulak harus memiliki minimal satu hasil sortir sebelum ditutup.');
            }
            if (! $p->closed_at) {
                $p->closed_at = now();
                $p->save();
                $this->audit('purchase.closed', $p, $ownerId, []);
            }

            return $this->present($p->fresh());
        });
    }

    public function reopen(Purchase $purchase, int $ownerId): Purchase
    {
        return DB::transaction(function () use ($purchase, $ownerId): Purchase {
            $p = Purchase::query()->lockForUpdate()->findOrFail($purchase->id);
            if ($p->closed_at) {
                $p->closed_at = null;
                $p->save();
                $this->audit('purchase.reopened', $p, $ownerId, []);
            }

            return $this->present($p->fresh());
        });
    }

    private function soldForPurchase(Purchase $purchase): int
    {
        $single = collect([$purchase]);
        $this->attachSoldMetrics($single);

        return (int) $purchase->getAttribute('_sold_milli');
    }

    /** Assign life-to-date FIFO sold kilograms without a per-purchase query. */
    private function attachSoldMetrics(Collection $purchases): void
    {
        $productIds = $purchases->flatMap(fn (Purchase $p) => $p->stockLots->pluck('product_id'))->unique()->values();
        if ($productIds->isEmpty()) {
            foreach ($purchases as $p) {
                $p->setAttribute('_sold_milli', 0);
            }

            return;
        }
        $soldByProduct = TransactionItem::query()->whereHas('transaction', fn ($q) => $q->where('status', 'completed'))
            ->whereIn('product_id', $productIds)->select('product_id')->selectRaw('SUM(weight_kg) as kg')->groupBy('product_id')->pluck('kg', 'product_id');
        $lots = StockLot::query()->whereIn('product_id', $productIds)->orderBy('product_id')->orderBy('sorted_at')->orderBy('id')->get(['id', 'purchase_id', 'product_id', 'weight_kg']);
        $soldByPurchase = [];
        foreach ($lots->groupBy('product_id') as $productId => $productLots) {
            $remaining = $this->averageCost->toMilli((string) ($soldByProduct[$productId] ?? '0'));
            foreach ($productLots as $lot) {
                $weight = $this->averageCost->toMilli((string) $lot->weight_kg);
                $allocated = min($weight, max(0, $remaining));
                $soldByPurchase[$lot->purchase_id] = ($soldByPurchase[$lot->purchase_id] ?? 0) + $allocated;
                $remaining -= $allocated;
            }
        }
        foreach ($purchases as $p) {
            $p->setAttribute('_sold_milli', $soldByPurchase[$p->id] ?? 0);
        }
    }

    private function sortingStatus(Purchase $purchase): string
    {
        return $purchase->closed_at ? 'closed' : ((int) $this->averageCost->toMilli((string) $purchase->sorted_raw) > 0 ? 'sorting' : 'unsorted');
    }

    private function sumWeight(array $weights): string
    {
        return $this->averageCost->formatWeight(array_sum(array_map(fn ($w) => $this->averageCost->toMilli((string) $w), $weights)));
    }

    private function audit(string $action, $entity, int $userId, array $payload): void
    {
        AuditLog::query()->create(['user_id' => $userId, 'action' => $action, 'entity_type' => $entity::class, 'entity_id' => $entity->id, 'payload' => $payload]);
    }
}
