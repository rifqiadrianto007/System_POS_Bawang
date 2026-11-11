<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\StockMovement;
use App\Models\StockLot;
use App\Models\Transaction;
use App\Models\TransactionItem;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

final class TransactionService
{
    public function __construct(
        private readonly PricingService $pricingService,
        private readonly AverageCostService $averageCost
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes, int $cashierId, bool $includeCosts = true): Transaction
    {
        return DB::transaction(function () use ($attributes, $cashierId, $includeCosts): Transaction {
            $existing = Transaction::query()
                ->where('client_uuid', $attributes['client_uuid'])
                ->with($this->transactionItemResponseRelation($includeCosts))
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $occurredAt = isset($attributes['occurred_at'])
                ? Carbon::parse($attributes['occurred_at'])
                : Carbon::now();
            $quote = $this->pricingService->quoteCart(
                $attributes['customer_category'],
                $attributes['items'],
            );
            $payment = $this->applyPaymentRules($quote['subtotal'], $attributes);
            $quote['discount'] = $payment['discount'];
            $quote['total'] = $payment['final_total'];

            $transaction = Transaction::query()->create([
                'invoice_no' => $this->nextInvoiceNumber($occurredAt),
                'client_uuid' => $attributes['client_uuid'],
                'cashier_id' => $cashierId,
                'customer_category' => $attributes['customer_category'],
                'customer_name' => $attributes['customer_name'] ?? null,
                'subtotal' => $quote['subtotal'],
                'discount' => $quote['discount'],
                'total' => $quote['total'],
                'amount_paid' => $payment['amount_paid'],
                'payment_method' => $attributes['payment_method'],
                'status' => 'completed',
                'occurred_at' => $occurredAt,
            ]);

            if ($quote['discount'] > 0) {
                AuditLog::query()->create([
                    'user_id' => $cashierId,
                    'action' => 'transaction.discount',
                    'entity_type' => Transaction::class,
                    'entity_id' => $transaction->id,
                    'payload' => [
                        'subtotal' => $quote['subtotal'],
                        'final_total' => $payment['final_total'],
                        'amount_paid' => $payment['amount_paid'],
                        'discount' => $quote['discount'],
                    ],
                ]);
            }

            foreach ($quote['items'] as $item) {
                $transactionItem = $transaction->items()->create([
                    'product_id' => $item['product_id'],
                    'product_name' => $item['product_name'],
                    'weight_kg' => $item['weight_kg'],
                    'price_tier' => $item['price_tier'],
                    'price_per_kg' => $item['price_per_kg'],
                    'line_total' => $item['line_total'],
                    'cogs' => 0,
                ]);
                $this->applySale($transactionItem, $occurredAt, $cashierId);
            }

            return $transaction->load($this->transactionItemResponseRelation($includeCosts));
        });
    }

    /** @return array<string, mixed> */
    private function transactionItemResponseRelation(bool $includeCosts): array
    {
        if ($includeCosts) {
            return ['items'];
        }

        return [
            'items' => static fn ($query) => $query->select([
                'id', 'transaction_id', 'product_id', 'product_name', 'weight_kg', 'price_tier', 'price_per_kg', 'line_total',
            ]),
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{final_total: int, amount_paid: int, discount: int}
     */
    private function applyPaymentRules(int $subtotal, array $attributes): array
    {
        $finalTotal = (int) $attributes['final_total'];
        $amountPaid = (int) $attributes['amount_paid'];

        if ($finalTotal < 1) {
            throw ValidationException::withMessages([
                'final_total' => 'Total akhir minimal Rp1.',
            ]);
        }

        if ($finalTotal > $subtotal) {
            throw ValidationException::withMessages([
                'final_total' => 'Total akhir tidak boleh lebih besar dari subtotal.',
            ]);
        }

        if ($amountPaid < $finalTotal) {
            throw ValidationException::withMessages([
                'amount_paid' => 'Uang bayar kurang dari total akhir.',
            ]);
        }

        if ($attributes['payment_method'] === 'qris' && $amountPaid !== $finalTotal) {
            throw ValidationException::withMessages([
                'amount_paid' => 'Pembayaran QRIS harus sama dengan total akhir.',
            ]);
        }

        return [
            'final_total' => $finalTotal,
            'amount_paid' => $amountPaid,
            'discount' => $subtotal - $finalTotal,
        ];
    }

    private function applySale(TransactionItem $transactionItem, Carbon $occurredAt, int $createdBy): void
    {
        $requiredMilli = $this->averageCost->toMilli((string) $transactionItem->weight_kg);
        $product = Product::query()->lockForUpdate()->findOrFail($transactionItem->product_id);
        $balance = $this->averageCost->stock($product);
        $stockMilli = $this->averageCost->toMilli($balance['stock_kg']);
        $coveredMilli = min($requiredMilli, max(0, $stockMilli));
        $shortfallMilli = $requiredMilli - $coveredMilli;
        $coveredCogs = $this->averageCost->cogs($coveredMilli, $stockMilli, $balance['stock_value']);
        $reserveCost = $this->fallbackUnitCost($product);
        $shortfallCogs = $this->averageCost->valueForWeight($shortfallMilli, $reserveCost);
        $cogs = $coveredCogs + $shortfallCogs;
        $transactionItem->update(['cogs' => $cogs, 'shortfall_kg' => $this->averageCost->formatWeight($shortfallMilli)]);
        $allocations = $this->allocateCoveredStock($product->id, $coveredMilli);
        $allocatedCovered = array_sum(array_column($allocations, 'weight_milli'));
        if ($allocatedCovered < $coveredMilli) {
            $allocations[] = ['lot_id' => null, 'weight_milli' => $coveredMilli - $allocatedCovered];
        }
        if ($shortfallMilli > 0) {
            $allocations[] = ['lot_id' => null, 'weight_milli' => $shortfallMilli, 'shortfall' => true];
        }

        $coveredValueAssigned = 0;
        $coveredAllocations = array_values(array_filter($allocations, static fn (array $allocation): bool => ! ($allocation['shortfall'] ?? false)));
        foreach ($allocations as $index => $allocation) {
            $isShortfall = (bool) ($allocation['shortfall'] ?? false);
            if ($isShortfall) {
                $movementValue = $shortfallCogs;
            } else {
                $coveredIndex = array_search($allocation, $coveredAllocations, true);
                $isLastCovered = $coveredIndex === array_key_last($coveredAllocations);
                $movementValue = $isLastCovered
                    ? $coveredCogs - $coveredValueAssigned
                    : $this->averageCost->valueForWeight((int) $allocation['weight_milli'], $balance['avg_cost'] ?? 0);
                $coveredValueAssigned += $movementValue;
            }

            StockMovement::query()->create([
                'product_id' => $product->id, 'lot_id' => $allocation['lot_id'], 'type' => 'sale',
                'weight_kg' => $this->averageCost->formatWeight(-(int) $allocation['weight_milli']), 'value_delta' => -$movementValue,
                'ref_type' => TransactionItem::class, 'ref_id' => $transactionItem->id,
                'occurred_at' => $occurredAt, 'created_by' => $createdBy,
                'note' => $isShortfall ? 'Penjualan dengan HPP cadangan' : 'Penjualan',
            ]);
        }
        $this->averageCost->updateLastUnitCost($product);
    }

    /** @return list<array{lot_id:int|null,weight_milli:int}> */
    private function allocateCoveredStock(int $productId, int $weightMilli): array
    {
        if ($weightMilli <= 0) {
            return [];
        }
        $lots = StockLot::query()->where('product_id', $productId)->orderBy('id')->get();
        $remaining = $weightMilli;
        $allocations = [];
        foreach ($lots as $lot) {
            $balance = (string) StockMovement::query()->where('lot_id', $lot->id)->sum('weight_kg');
            $available = max(0, $this->averageCost->toMilli($balance));
            $allocated = min($available, $remaining);
            if ($allocated > 0) {
                $allocations[] = ['lot_id' => (int) $lot->id, 'weight_milli' => $allocated];
                $remaining -= $allocated;
            }
            if ($remaining === 0) {
                break;
            }
        }

        return $allocations;
    }

    private function fallbackUnitCost(Product $product): int
    {
        if ($product->last_unit_cost !== null) {
            return (int) $product->last_unit_cost;
        }
        $row = Purchase::query()->where('jenis', $product->jenis)->selectRaw('SUM(total_cost) AS cost, SUM(gross_weight_kg) AS kg')->first();
        $milli = $this->averageCost->toMilli((string) ($row->kg ?? '0'));
        if ($milli <= 0) {
            return 0;
        }
        $numerator = (int) $row->cost * 1000;
        $quotient = intdiv($numerator, $milli);

        return $quotient + (($numerator % $milli) * 2 >= $milli ? 1 : 0);
    }

    public function void(Transaction $transaction, int $ownerId, string $reason): Transaction
    {
        return DB::transaction(function () use ($transaction, $ownerId, $reason): Transaction {
            $transaction = Transaction::query()
                ->lockForUpdate()
                ->with('items')
                ->findOrFail($transaction->id);

            if ($transaction->status === 'void') {
                throw new LogicException('Transaksi sudah di-void.');
            }

            $transaction->update([
                'status' => 'void',
                'void_reason' => $reason,
                'voided_by' => $ownerId,
                'voided_at' => now(),
            ]);

            foreach ($transaction->items as $item) {
                $sales = StockMovement::query()->where('type', 'sale')->where('ref_type', TransactionItem::class)->where('ref_id', $item->id)->get();
                foreach ($sales as $sale) {
                    StockMovement::query()->create([
                        'product_id' => $sale->product_id,
                        'lot_id' => $sale->lot_id,
                        'type' => 'void_return',
                        'weight_kg' => $this->averageCost->formatWeight(-$this->averageCost->toMilli((string) $sale->weight_kg)),
                        'value_delta' => -(int) $sale->value_delta,
                        'ref_type' => TransactionItem::class,
                        'ref_id' => $item->id,
                        'occurred_at' => now(),
                        'created_by' => $ownerId,
                        'note' => 'Pengembalian transaksi void',
                    ]);
                }
                $this->averageCost->updateLastUnitCost(Product::query()->findOrFail($item->product_id));
            }

            AuditLog::query()->create([
                'user_id' => $ownerId,
                'action' => 'transaction.void',
                'entity_type' => Transaction::class,
                'entity_id' => $transaction->id,
                'payload' => [
                    'reason' => $reason,
                    'invoice_no' => $transaction->invoice_no,
                ],
            ]);

            return $transaction->load('items');
        });
    }

    private function nextInvoiceNumber(Carbon $occurredAt): string
    {
        $date = $occurredAt->copy()->setTimezone('Asia/Jakarta');
        $prefix = 'POS-'.$date->format('ymd').'-';
        $lastInvoice = Transaction::query()
            ->where('invoice_no', 'like', $prefix.'%')
            ->lockForUpdate()
            ->orderByDesc('invoice_no')
            ->value('invoice_no');
        $sequence = $lastInvoice === null ? 1 : ((int) substr($lastInvoice, -4)) + 1;

        return $prefix.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }
}
