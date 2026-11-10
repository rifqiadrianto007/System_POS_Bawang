<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Purchase;
use App\Models\Product;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\TransactionItem;
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
        $query = Purchase::query()->with(['supplier:id,name', 'stockLots.product'])->withSum('stockLots as counted_raw', 'weight_kg');
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
            'display_kg' => '0.000', 'remaining_weight_kg' => '0.000', 'unsorted_count' => 0, 'sorted_count' => 0];
        $display = 0;
        $remaining = 0;
        foreach ($all as $purchase) {
            $gross = $this->averageCost->toMilli((string) $purchase->gross_weight_kg);
            $sorted = $this->averageCost->toMilli((string) ($purchase->counted_raw ?? '0'));
            $display += min($gross, $sorted);
            $remaining += max(0, $gross - $sorted);
            $summary[$sorted > 0 ? 'sorted_count' : 'unsorted_count']++;
        }
        $summary['display_kg'] = $this->averageCost->formatWeight($display);
        $summary['remaining_weight_kg'] = $this->averageCost->formatWeight($remaining);

        if (isset($filters['status_sortir'])) {
            $ids = $all->filter(fn (Purchase $purchase): bool => $this->statusFor($purchase) === $filters['status_sortir'])->modelKeys();
            $query->whereIn('id', $ids);
        }
        $includeLots = ($filters['include'] ?? null) === 'lots';
        $purchases = $query->orderByDesc('purchased_at')->orderByDesc('id')
            ->paginate(min(50, (int) ($filters['per_page'] ?? 20)), ['*'], 'page', (int) ($filters['page'] ?? 1));
        $purchases->getCollection()->transform(function (Purchase $purchase) use ($includeLots): Purchase {
            $purchase = $this->present($purchase);
            $purchase->setAttribute('include_lots', $includeLots);

            return $purchase;
        });

        return ['purchases' => $purchases, 'summary' => $summary];
    }

    public function present(Purchase $purchase): Purchase
    {
        $purchase->loadMissing(['supplier:id,name', 'stockLots.product']);
        $gross = $this->averageCost->toMilli((string) ($purchase->gross_weight_kg ?? '0'));
        $sorted = 0;
        foreach ($purchase->stockLots as $lot) {
            $sorted += $this->averageCost->toMilli((string) ($lot->weight_kg ?? '0'));
        }
        $remaining = max(0, $gross - $sorted);
        $percentage = $gross > 0 ? intdiv(min($gross, $sorted) * 100 * 2 + $gross, 2 * $gross) : 0;
        $lots = $purchase->stockLots->map(fn (StockLot $lot) => [
            'id' => (int) $lot->id,
            'purchase_id' => (int) $lot->purchase_id,
            'product_id' => (int) $lot->product_id,
            'product_name' => $lot->product?->name ?? '',
            'clean_weight_kg' => (string) ($lot->weight_kg ?? '0.000'),
        ])->values()->all();

        $purchase->setAttribute('sorted_weight_kg', $this->averageCost->formatWeight(min($gross, $sorted)));
        $purchase->setAttribute('remaining_weight_kg', $this->averageCost->formatWeight($remaining));
        $purchase->setAttribute('sort_percentage', $percentage);
        $purchase->setAttribute('status_sortir', $sorted > 0 ? 'tersortir' : 'belum_disortir');
        $sold = $this->soldForPurchase($purchase);
        $purchase->setAttribute('sorted_kg', $this->averageCost->formatWeight(min($gross, $sorted)));
        $purchase->setAttribute('unsorted_kg', $this->averageCost->formatWeight($remaining));
        $purchase->setAttribute('sold_kg', $this->averageCost->formatWeight($sold));
        $purchase->setAttribute('susut_kg', $this->averageCost->formatWeight(max(0, $gross - $sold)));
        $purchase->setAttribute('can_edit', $purchase->stockLots->isEmpty());
        $purchase->setAttribute('can_delete', $purchase->stockLots->isEmpty());
        $purchase->setAttribute('lots', $lots);

        return $purchase;
    }

    /** @param Collection<int,Purchase> $purchases @return Collection<int,Purchase> */
    public function deriveMetrics(Collection $purchases): Collection
    {
        $purchases->loadMissing(['supplier:id,name', 'stockLots.product']);

        return $purchases->map(fn (Purchase $purchase): Purchase => $this->present($purchase));
    }

    public function update(Purchase $purchase, array $attributes, int $ownerId): Purchase
    {
        return DB::transaction(function () use ($purchase, $attributes, $ownerId): Purchase {
            $purchase = Purchase::query()->with('stockLots')->lockForUpdate()->findOrFail($purchase->id);
            if ($purchase->stockLots->isNotEmpty() && array_diff(array_keys($attributes), ['notes', 'supplier_id']) !== []) {
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
            if ($p->stockLots->isNotEmpty()) {
                throw new LogicException('Kulak yang sudah memiliki hitung ulang tidak dapat dihapus.');
            }
            $this->audit('purchase.deleted', $p, $ownerId, $p->only(['code', 'supplier_id', 'purchased_at', 'gross_weight_kg', 'jenis', 'total_cost']));
            $p->delete();
        });
    }

    private function statusFor(Purchase $purchase): string
    {
        return $this->averageCost->toMilli((string) ($purchase->counted_raw ?? '0')) > 0 ? 'tersortir' : 'belum_disortir';
    }

    private function soldForPurchase(Purchase $purchase): int
    {
        $productIds = $purchase->stockLots->pluck('product_id')->unique();
        if ($productIds->isEmpty()) {
            return 0;
        }
        $soldByProduct = TransactionItem::query()->whereHas('transaction', fn ($query) => $query->where('status', 'completed'))
            ->whereIn('product_id', $productIds)->select('product_id')->selectRaw('SUM(weight_kg) AS kg')->groupBy('product_id')->pluck('kg', 'product_id');
        $lots = StockLot::query()->whereIn('product_id', $productIds)->orderBy('product_id')->orderBy('sorted_at')->orderBy('id')->get(['purchase_id', 'product_id', 'weight_kg']);
        $soldByPurchase = [];
        foreach ($lots->groupBy('product_id') as $productId => $productLots) {
            $remainingSold = $this->averageCost->toMilli((string) ($soldByProduct[$productId] ?? '0'));
            foreach ($productLots as $lot) {
                $weight = $this->averageCost->toMilli((string) $lot->weight_kg);
                $sold = min($weight, max(0, $remainingSold));
                $soldByPurchase[$lot->purchase_id] = ($soldByPurchase[$lot->purchase_id] ?? 0) + $sold;
                $remainingSold -= $sold;
            }
        }

        return (int) ($soldByPurchase[$purchase->id] ?? 0);
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
