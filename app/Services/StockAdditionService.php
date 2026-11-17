<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\StockAddition;
use App\Models\StockMovement;
use App\Models\Transaction;
use App\Models\TransactionItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

final class StockAdditionService
{
    public function __construct(private readonly AverageCostService $averageCost) {}

    /** @param array{batch_id:int,product_id:int,weight_kg:string} $attributes */
    public function create(array $attributes, int $ownerId): StockAddition
    {
        return DB::transaction(function () use ($attributes, $ownerId): StockAddition {
            $purchase = Purchase::query()->lockForUpdate()->findOrFail($attributes['batch_id']);
            $product = Product::query()->lockForUpdate()->findOrFail($attributes['product_id']);
            if ($purchase->sorted_finished_at !== null) {
                throw new LogicException('Batch yang sudah selesai disortir tidak menerima Tambah stok.');
            }
            if ($purchase->jenis !== $product->jenis) {
                throw ValidationException::withMessages(['product_id' => 'Jenis produk harus sama dengan jenis batch.']);
            }

            $weightMilli = $this->averageCost->toMilli((string) $attributes['weight_kg']);
            $grossMilli = $this->averageCost->toMilli((string) $purchase->gross_weight_kg);
            $addedMilli = $this->averageCost->toMilli((string) DB::table('stock_additions')
                ->where('purchase_id', $purchase->id)->sum('weight_kg'));
            if ($weightMilli > $grossMilli - $addedMilli) {
                throw ValidationException::withMessages(['weight_kg' => 'Berat Tambah tidak boleh melebihi sisa batch.']);
            }

            $date = now('Asia/Jakarta')->toDateString();
            $addition = StockAddition::query()->create([
                'purchase_id' => $purchase->id,
                'product_id' => $product->id,
                'weight_kg' => $this->averageCost->formatWeight($weightMilli),
                'added_at' => $date,
                'created_by' => $ownerId,
            ]);
            $value = $this->averageCost->valueForWeight($weightMilli, (int) $purchase->price_per_kg);
            StockMovement::query()->create([
                'product_id' => $product->id,
                'lot_id' => null,
                'type' => 'in_sort',
                'weight_kg' => $this->averageCost->formatWeight($weightMilli),
                'value_delta' => $value,
                'ref_type' => StockAddition::class,
                'ref_id' => $addition->id,
                'occurred_at' => now(),
                'created_by' => $ownerId,
                'note' => 'Tambah stok display dari batch',
            ]);

            $backfilled = $this->backfillShortfall($product, $weightMilli, $ownerId);
            $this->averageCost->updateLastUnitCost($product);

            AuditLog::query()->create([
                'user_id' => $ownerId,
                'action' => 'stock.added',
                'entity_type' => StockAddition::class,
                'entity_id' => $addition->id,
                'payload' => [
                    'batch_id' => $purchase->id,
                    'product_id' => $product->id,
                    'weight_kg' => $this->averageCost->formatWeight($weightMilli),
                    'added_at' => $date,
                    'backfilled_items' => $backfilled,
                ],
            ]);

            return $addition->fresh();
        });
    }

    /** @return list<array{transaction_id:int,transaction_item_id:int,weight_kg:string,cogs_before:int,cogs_after:int,cogs_delta:int}> */
    private function backfillShortfall(Product $product, int $availableMilli, int $ownerId): array
    {
        $incoming = StockMovement::query()->where('product_id', $product->id)->where('weight_kg', '>', 0)
            ->where('value_delta', '>', 0)->whereIn('type', ['in_sort', 'void_return', 'count_adjust'])
            ->selectRaw('COALESCE(SUM(weight_kg),0) AS kg, COALESCE(SUM(value_delta),0) AS value')->first();
        $weightedUnitCost = $this->averageCost->averageUnitCost(
            $this->averageCost->toMilli((string) $incoming->kg),
            (int) $incoming->value,
        );
        $items = TransactionItem::query()
            ->join('transactions', 'transactions.id', '=', 'transaction_items.transaction_id')
            ->where('transaction_items.product_id', $product->id)
            ->where('transactions.status', 'completed')
            ->where('transactions.hpp_provisional', true)
            ->whereRaw('transaction_items.shortfall_resolved_kg < transaction_items.shortfall_kg')
            ->orderBy('transactions.occurred_at')->orderBy('transaction_items.id')
            ->select('transaction_items.*')->lockForUpdate()->get();

        $backfilledItems = [];
        foreach ($items as $item) {
            if ($availableMilli <= 0) {
                break;
            }
            $shortfallMilli = $this->averageCost->toMilli((string) $item->shortfall_kg);
            $resolvedMilli = $this->averageCost->toMilli((string) $item->shortfall_resolved_kg);
            $amountMilli = min($availableMilli, $shortfallMilli - $resolvedMilli);
            if ($amountMilli <= 0) {
                continue;
            }

            $unitCostDelta = (int) ($weightedUnitCost ?? $item->shortfall_unit_cost) - (int) $item->shortfall_unit_cost;
            $magnitude = $this->averageCost->valueForWeight($amountMilli, abs($unitCostDelta));
            $cogsDelta = $unitCostDelta < 0 ? -$magnitude : $magnitude;
            $cogsBefore = (int) $item->cogs;
            $cogsAfter = $cogsBefore + $cogsDelta;
            $item->forceFill([
                'cogs' => $cogsAfter,
                'shortfall_resolved_kg' => $this->averageCost->formatWeight($resolvedMilli + $amountMilli),
            ])->save();

            if ($cogsDelta !== 0) {
                StockMovement::query()->create([
                    'product_id' => $product->id,
                    'lot_id' => null,
                    'type' => 'sale_cogs_adjustment',
                    'weight_kg' => '0.000',
                    'value_delta' => -$cogsDelta,
                    'ref_type' => TransactionItem::class,
                    'ref_id' => $item->id,
                    'occurred_at' => now(),
                    'created_by' => $ownerId,
                    'note' => 'Koreksi HPP provisional setelah stok ditambah',
                ]);
            }

            $backfillEntry = [
                'transaction_id' => (int) $item->transaction_id,
                'transaction_item_id' => (int) $item->id,
                'weight_kg' => $this->averageCost->formatWeight($amountMilli),
                'cogs_before' => $cogsBefore,
                'cogs_after' => $cogsAfter,
                'cogs_delta' => $cogsDelta,
            ];
            $backfilledItems[] = $backfillEntry;
            AuditLog::query()->create([
                'user_id' => $ownerId,
                'action' => 'transaction.hpp_backfilled',
                'entity_type' => Transaction::class,
                'entity_id' => $item->transaction_id,
                'payload' => [...$backfillEntry, 'product_id' => $product->id, 'cost_basis' => 'product_weighted_average'],
            ]);
            $availableMilli -= $amountMilli;
        }

        foreach (array_unique(array_map(static fn (TransactionItem $item): int => (int) $item->transaction_id, $items->all())) as $transactionId) {
            $transaction = Transaction::query()->lockForUpdate()->find($transactionId);
            if ($transaction !== null && ! $transaction->items()->whereRaw('shortfall_resolved_kg < shortfall_kg')->exists()) {
                $transaction->forceFill(['hpp_provisional' => false])->save();
            }
        }

        return $backfilledItems;
    }
}
