<?php

namespace App\Http\Controllers;

use App\Http\Requests\TransactionIndexRequest;
use App\Http\Resources\TransactionResource;
use App\Models\Transaction;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;

class TransactionHistoryController extends Controller
{
    public function __invoke(TransactionIndexRequest $request): AnonymousResourceCollection
    {
        $user = $request->user();
        $query = Transaction::query()
            ->with('items')
            ->orderByDesc('occurred_at')
            ->orderByDesc('id');

        if ($user->role === 'kasir') {
            $today = Carbon::now('Asia/Jakarta');
            $query->where('cashier_id', $user->id)
                ->whereBetween('occurred_at', [
                    $today->copy()->startOfDay()->utc(),
                    $today->copy()->endOfDay()->utc(),
                ])
                ->where('status', 'completed');
        } else {
            if ($request->filled('from')) {
                $query->where('occurred_at', '>=', Carbon::createFromFormat('Y-m-d', $request->string('from')->toString(), 'Asia/Jakarta')->startOfDay()->utc());
            }

            if ($request->filled('to')) {
                $query->where('occurred_at', '<=', Carbon::createFromFormat('Y-m-d', $request->string('to')->toString(), 'Asia/Jakarta')->endOfDay()->utc());
            }

            if ($request->filled('status')) {
                $query->where('status', $request->string('status')->toString());
            }

            if ($request->filled('cashier_id')) {
                $query->where('cashier_id', $request->integer('cashier_id'));
            }
        }

        return TransactionResource::collection($query->paginate($request->integer('per_page', 20)));
    }
}
