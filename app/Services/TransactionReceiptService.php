<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\User;
use App\Support\CashierTransactionWindow;
use Illuminate\Auth\Access\AuthorizationException;

final class TransactionReceiptService
{
    public function get(Transaction $transaction, User $user, bool $useSharedHistoryWindow = false): Transaction
    {
        if ($user->role === 'kasir' && $useSharedHistoryWindow) {
            [$start, $end] = CashierTransactionWindow::bounds();
            $occurredAt = $transaction->occurred_at?->copy()->utc();
            if ($occurredAt === null || $occurredAt->lt($start) || $occurredAt->gte($end)) {
                abort(404, 'Data tidak ditemukan.');
            }
        } elseif ($user->role === 'kasir' && $transaction->cashier_id !== $user->id) {
            throw new AuthorizationException('Kasir hanya dapat melihat nota miliknya.');
        }

        if ($user->role === 'kasir') {
            return $transaction->load([
                'items' => fn ($query) => $query->select([
                    'id', 'transaction_id', 'product_id', 'product_name', 'weight_kg', 'price_tier', 'price_per_kg', 'line_total',
                ]),
                'cashier',
            ]);
        }

        return $transaction->load(['items', 'cashier']);
    }
}
