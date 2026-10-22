<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Expense;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class ExpenseService
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes, int $ownerId): Expense
    {
        return DB::transaction(function () use ($attributes, $ownerId): Expense {
            $expense = Expense::query()->create([
                ...$attributes,
                'created_by' => $ownerId,
            ]);

            $this->recordAudit('expense.created', $expense, $ownerId, $attributes);

            return $expense;
        });
    }

    public function list(): Collection
    {
        return Expense::query()->latest('spent_at')->latest('id')->get();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Expense $expense, array $attributes, int $ownerId): Expense
    {
        return DB::transaction(function () use ($expense, $attributes, $ownerId): Expense {
            $expense->update($attributes);
            $this->recordAudit('expense.updated', $expense, $ownerId, $attributes);

            return $expense->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function recordAudit(string $action, Expense $expense, int $ownerId, array $payload): void
    {
        AuditLog::query()->create([
            'user_id' => $ownerId,
            'action' => $action,
            'entity_type' => Expense::class,
            'entity_id' => $expense->id,
            'payload' => $payload,
        ]);
    }
}
