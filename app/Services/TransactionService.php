<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\SaleAllocation;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\Transaction;
use App\Models\TransactionItem;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

final class TransactionService
{
    public function __construct(private readonly PricingService $pricingService) {}

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
                $this->allocateItem($transactionItem, $occurredAt, $cashierId);
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

    private function allocateItem(TransactionItem $transactionItem, Carbon $occurredAt, int $createdBy): void
    {
        $requiredMilli = $this->toMilliKilograms((string) $transactionItem->weight_kg);
        $lots = StockLot::query()
            ->where('product_id', $transactionItem->product_id)
            ->with('movements')
            ->orderBy('cleaned_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        $remainingMilli = $requiredMilli;
        $cogsNumerator = 0;

        foreach ($lots as $lot) {
            if ($remainingMilli <= 0) {
                break;
            }

            $availableMilli = $this->sumMovementWeight($lot->movements);
            if ($availableMilli <= 0) {
                continue;
            }

            $allocatedMilli = min($remainingMilli, $availableMilli);
            $this->createAllocation(
                $transactionItem,
                $lot,
                $allocatedMilli,
                (int) $lot->unit_cost,
                false,
                $occurredAt,
                $createdBy
            );
            $remainingMilli -= $allocatedMilli;
            $cogsNumerator += $allocatedMilli * (int) $lot->unit_cost;
        }

        if ($remainingMilli > 0) {
            $latestLot = $lots->last();
            $unitCost = $latestLot?->unit_cost === null ? 0 : (int) $latestLot->unit_cost;
            $this->createAllocation(
                $transactionItem,
                $latestLot,
                $remainingMilli,
                $unitCost,
                true,
                $occurredAt,
                $createdBy
            );
            $cogsNumerator += $remainingMilli * $unitCost;
        }

        $transactionItem->update(['cogs' => $this->roundHalfUp($cogsNumerator, 1000)]);
    }

    private function createAllocation(
        TransactionItem $transactionItem,
        ?StockLot $lot,
        int $weightMilli,
        int $unitCost,
        bool $isShortfall,
        Carbon $occurredAt,
        int $createdBy
    ): void {
        SaleAllocation::query()->create([
            'transaction_item_id' => $transactionItem->id,
            'lot_id' => $lot?->id,
            'weight_kg' => $this->formatWeight($weightMilli),
            'unit_cost' => $unitCost,
            'is_shortfall' => $isShortfall,
        ]);

        if ($lot !== null) {
            StockMovement::query()->create([
                'product_id' => $transactionItem->product_id,
                'lot_id' => $lot->id,
                'type' => 'sale',
                'weight_kg' => $this->formatWeight(-$weightMilli),
                'unit_cost' => $unitCost,
                'ref_type' => Transaction::class,
                'ref_id' => $transactionItem->transaction_id,
                'occurred_at' => $occurredAt,
                'created_by' => $createdBy,
                'note' => $isShortfall ? 'Shortfall penjualan' : 'Alokasi penjualan FIFO',
            ]);
        }
    }

    private function sumMovementWeight(iterable $movements): int
    {
        $total = 0;

        foreach ($movements as $movement) {
            $total += $this->toMilliKilograms((string) $movement->weight_kg);
        }

        return $total;
    }

    private function toMilliKilograms(string $weight): int
    {
        $normalized = ltrim(trim($weight), '+');
        $negative = str_starts_with($normalized, '-');
        $normalized = ltrim($normalized, '-');
        [$whole, $fraction] = array_pad(explode('.', $normalized, 2), 2, '');
        $value = ((int) $whole * 1000) + (int) str_pad(substr($fraction, 0, 3), 3, '0');

        return $negative ? -$value : $value;
    }

    private function formatWeight(int $weightMilli): string
    {
        $sign = $weightMilli < 0 ? '-' : '';
        $absolute = abs($weightMilli);

        return $sign.intdiv($absolute, 1000).'.'.str_pad((string) ($absolute % 1000), 3, '0', STR_PAD_LEFT);
    }

    private function roundHalfUp(int $numerator, int $denominator): int
    {
        return $numerator < 0
            ? -intdiv(abs($numerator) * 2 + $denominator, $denominator * 2)
            : intdiv($numerator * 2 + $denominator, $denominator * 2);
    }

    public function void(Transaction $transaction, int $ownerId, string $reason): Transaction
    {
        return DB::transaction(function () use ($transaction, $ownerId, $reason): Transaction {
            $transaction = Transaction::query()
                ->lockForUpdate()
                ->with('items.saleAllocations')
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
                foreach ($item->saleAllocations as $allocation) {
                    if ($allocation->lot_id === null) {
                        continue;
                    }

                    StockMovement::query()->create([
                        'product_id' => $item->product_id,
                        'lot_id' => $allocation->lot_id,
                        'type' => 'void_return',
                        'weight_kg' => $allocation->weight_kg,
                        'unit_cost' => $allocation->unit_cost,
                        'ref_type' => Transaction::class,
                        'ref_id' => $transaction->id,
                        'occurred_at' => now(),
                        'created_by' => $ownerId,
                        'note' => 'Pengembalian stok transaksi void',
                    ]);
                }
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
