<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTransactionRequest;
use App\Http\Requests\VoidTransactionRequest;
use App\Http\Resources\TransactionReceiptResource;
use App\Http\Resources\TransactionResource;
use App\Models\Transaction;
use App\Services\TransactionReceiptService;
use App\Services\TransactionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use InvalidArgumentException;
use LogicException;

class TransactionController extends Controller
{
    public function store(StoreTransactionRequest $request, TransactionService $transactionService): JsonResponse
    {
        try {
            $transaction = $transactionService->create($request->validated(), $request->user()->id);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        $status = $transaction->wasRecentlyCreated ? 201 : 200;

        return (new TransactionResource($transaction))->response()->setStatusCode($status);
    }

    public function void(
        VoidTransactionRequest $request,
        Transaction $transaction,
        TransactionService $transactionService
    ): JsonResponse {
        try {
            $transaction = $transactionService->void(
                $transaction,
                $request->user()->id,
                $request->validated('reason')
            );
        } catch (LogicException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return (new TransactionResource($transaction))->response();
    }

    public function receipt(
        Transaction $transaction,
        TransactionReceiptService $receiptService
    ): TransactionReceiptResource {
        return new TransactionReceiptResource(
            $receiptService->get($transaction, request()->user())
        );
    }

    public function show(
        Transaction $transaction,
        TransactionReceiptService $receiptService
    ): TransactionResource {
        return new TransactionResource(
            $receiptService->get($transaction, request()->user())
        );
    }

    public function printReceipt(
        Transaction $transaction,
        TransactionReceiptService $receiptService
    ): Response {
        return response()->view('transactions.receipt-print', [
            'transaction' => $receiptService->get($transaction, request()->user()),
        ]);
    }
}
