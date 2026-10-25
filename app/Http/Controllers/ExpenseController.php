<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreExpenseRequest;
use App\Http\Requests\UpdateExpenseRequest;
use App\Http\Resources\ExpenseResource;
use App\Models\Expense;
use App\Services\ExpenseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ExpenseController extends Controller
{
    public function index(ExpenseService $expenseService): AnonymousResourceCollection
    {
        return ExpenseResource::collection($expenseService->list());
    }

    public function store(StoreExpenseRequest $request, ExpenseService $expenseService): JsonResponse
    {
        $expense = $expenseService->create($request->validated(), $request->user()->id);

        return (new ExpenseResource($expense))->response()->setStatusCode(201);
    }

    public function update(
        UpdateExpenseRequest $request,
        Expense $expense,
        ExpenseService $expenseService
    ): ExpenseResource {
        return new ExpenseResource(
            $expenseService->update($expense, $request->validated(), $request->user()->id)
        );
    }
}
