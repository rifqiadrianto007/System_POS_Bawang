<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\TransactionHistoryController;
use App\Http\Controllers\TransactionQuoteController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::post('/auth/login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::get('/owner/ping', fn () => response()->json(['message' => 'Akses owner berhasil.']))
            ->middleware('role:owner');
        Route::get('/products', [ProductController::class, 'index']);
        Route::post('/products', [ProductController::class, 'store'])->middleware('role:owner');
        Route::put('/products/{product}', [ProductController::class, 'update'])->middleware('role:owner');
        Route::patch('/products/{product}/active', [ProductController::class, 'updateStatus'])->middleware('role:owner');
        Route::get('/products/{product}/price-history', [ProductController::class, 'priceHistory'])
            ->middleware('role:owner');
        Route::post('/transactions/quote', TransactionQuoteController::class);
        Route::post('/transactions', [TransactionController::class, 'store']);
        Route::post('/transactions/{transaction}/void', [TransactionController::class, 'void'])
            ->middleware('role:owner');
        Route::get('/transactions/{transaction}/receipt', [TransactionController::class, 'receipt']);
        Route::get('/transactions', TransactionHistoryController::class);
    });
});
