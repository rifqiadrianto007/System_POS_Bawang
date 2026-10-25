<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

final class TransactionReceiptService
{
    public function get(Transaction $transaction, User $user): Transaction
    {
        if ($user->role === 'kasir' && $transaction->cashier_id !== $user->id) {
            throw new AuthorizationException('Kasir hanya dapat melihat nota miliknya.');
        }

        return $transaction->load(['items', 'cashier']);
    }
}
