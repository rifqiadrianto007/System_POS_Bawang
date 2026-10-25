<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\PurchaseController;
use App\Http\Controllers\StockController;
use App\Http\Controllers\StockCountController;
use App\Http\Controllers\SupplierController;
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
        Route::middleware('role:owner')->group(function (): void {
            Route::get('/suppliers', [SupplierController::class, 'index']);
            Route::post('/suppliers', [SupplierController::class, 'store']);
            Route::put('/suppliers/{supplier}', [SupplierController::class, 'update']);
            Route::get('/purchases', [PurchaseController::class, 'index']);
            Route::post('/purchases', [PurchaseController::class, 'store']);
            Route::post('/purchases/{purchase}/lots', [PurchaseController::class, 'addLot']);
            Route::delete('/purchases/{purchase}/lots/{lot}', [PurchaseController::class, 'deleteLot']);
            Route::post('/purchases/{purchase}/finalize', [PurchaseController::class, 'finalize']);
            Route::get('/purchases/{purchase}', [PurchaseController::class, 'show']);
            Route::get('/stock', StockController::class);
            Route::post('/stock-counts', [StockCountController::class, 'store']);
            Route::get('/stock-counts', [StockCountController::class, 'index']);
            Route::get('/expenses', [ExpenseController::class, 'index']);
            Route::post('/expenses', [ExpenseController::class, 'store']);
            Route::put('/expenses/{expense}', [ExpenseController::class, 'update']);
        });
        Route::post('/transactions/quote', TransactionQuoteController::class);
        Route::post('/transactions', [TransactionController::class, 'store']);
        Route::post('/transactions/{transaction}/void', [TransactionController::class, 'void'])
            ->middleware('role:owner');
        Route::get('/transactions/{transaction}/receipt', [TransactionController::class, 'receipt']);
        Route::get('/transactions', TransactionHistoryController::class);
    });
});
