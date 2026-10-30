<?php

namespace App\Http\Controllers;

use App\Enums\ExpenseCategory;
use App\Http\Requests\DeleteExpenseRequest;
use App\Http\Requests\ExpenseIndexRequest;
use App\Http\Requests\ExpenseSuggestionRequest;
use App\Http\Requests\StoreExpenseRequest;
use App\Http\Requests\UpdateExpenseRequest;
use App\Http\Resources\ExpenseResource;
use App\Models\Expense;
use App\Services\ExpenseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ExpenseController extends Controller
{
    public function index(ExpenseIndexRequest $request, ExpenseService $expenseService): AnonymousResourceCollection
    {
        $result = $expenseService->list($request->validated());

        return ExpenseResource::collection($result['expenses'])->additional(['summary' => $result['summary']]);
    }

    public function categories(): JsonResponse
    {
        return response()->json(['data' => ExpenseCategory::options()]);
    }

    public function suggestions(ExpenseSuggestionRequest $request, ExpenseService $expenseService): JsonResponse
    {
        return response()->json(['data' => $expenseService->suggestions($request->validated('q'))]);
    }

    public function store(StoreExpenseRequest $request, ExpenseService $expenseService): JsonResponse
    {
        $expense = $expenseService->create($request->validated(), $request->user()->id);

        return (new ExpenseResource($expense))->additional([
            'warnings' => $expenseService->warningsForName($expense->name),
        ])->response()->setStatusCode(201);
    }

    public function update(
        UpdateExpenseRequest $request,
        Expense $expense,
        ExpenseService $expenseService
    ): JsonResponse {
        $updated = $expenseService->update($expense, $request->validated(), $request->user()->id);

        return (new ExpenseResource($updated))->additional([
            'warnings' => $expenseService->warningsForName($updated->name),
        ])->response();
    }

    public function destroy(
        DeleteExpenseRequest $request,
        Expense $expense,
        ExpenseService $expenseService
    ): JsonResponse {
        $expenseService->delete($expense, $request->user()->id, $request->validated('reason'));

        return response()->json(['message' => 'Pengeluaran dihapus.']);
    }
}
