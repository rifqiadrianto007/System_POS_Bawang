<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;

final class TransactionService
{
    public function __construct(private readonly PricingService $pricingService) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes, int $cashierId): Transaction
    {
        return DB::transaction(function () use ($attributes, $cashierId): Transaction {
            $existing = Transaction::query()
                ->where('client_uuid', $attributes['client_uuid'])
                ->with('items')
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
                $attributes['final_total'] ?? null,
            );

            $transaction = Transaction::query()->create([
                'invoice_no' => $this->nextInvoiceNumber($occurredAt),
                'client_uuid' => $attributes['client_uuid'],
                'cashier_id' => $cashierId,
                'customer_category' => $attributes['customer_category'],
                'customer_name' => $attributes['customer_name'] ?? null,
                'subtotal' => $quote['subtotal'],
                'discount' => $quote['discount'],
                'total' => $quote['total'],
                'payment_method' => $attributes['payment_method'],
                'status' => 'completed',
                'occurred_at' => $occurredAt,
            ]);

            foreach ($quote['items'] as $item) {
                $transaction->items()->create([
                    'product_id' => $item['product_id'],
                    'product_name' => $item['product_name'],
                    'weight_kg' => $item['weight_kg'],
                    'price_tier' => $item['price_tier'],
                    'price_per_kg' => $item['price_per_kg'],
                    'line_total' => $item['line_total'],
                    'cogs' => 0, // TODO S2-04
                ]);
            }

            return $transaction->load('items');
        });
    }

    public function void(Transaction $transaction, int $ownerId, string $reason): Transaction
    {
        return DB::transaction(function () use ($transaction, $ownerId, $reason): Transaction {
            $transaction = Transaction::query()->lockForUpdate()->findOrFail($transaction->id);

            if ($transaction->status === 'void') {
                throw new LogicException('Transaksi sudah di-void.');
            }

            $transaction->update([
                'status' => 'void',
                'void_reason' => $reason,
                'voided_by' => $ownerId,
                'voided_at' => now(),
            ]);

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
