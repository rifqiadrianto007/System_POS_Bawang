<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Expense;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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

            return $expense->load('creator');
        });
    }

    /** @param array<string, mixed> $filters
     *  @return array{expenses: LengthAwarePaginator, summary: array{total_amount: int, count: int}}
     */
    public function list(array $filters): array
    {
        $today = Carbon::now('Asia/Jakarta');
        $from = (string) ($filters['from'] ?? $today->copy()->startOfMonth()->toDateString());
        $to = (string) ($filters['to'] ?? $today->toDateString());
        $toExclusive = Carbon::createFromFormat('!Y-m-d', $to, 'Asia/Jakarta')->addDay()->toDateString();
        $query = Expense::query()->with('creator:id,name')
            ->where('spent_at', '>=', $from)
            ->where('spent_at', '<', $toExclusive);

        if (isset($filters['category']) && $filters['category'] !== '') {
            $query->where('category', $filters['category']);
        }

        if (isset($filters['q']) && $filters['q'] !== '') {
            $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], (string) $filters['q']);
            $pattern = '%'.$escaped.'%';
            $query->where(function ($builder) use ($pattern): void {
                $builder->whereRaw("LOWER(name) LIKE LOWER(?) ESCAPE '!'", [$pattern])
                    ->orWhereRaw("LOWER(notes) LIKE LOWER(?) ESCAPE '!'", [$pattern]);
            });
        }

        $summaryQuery = clone $query;
        $summary = [
            'total_amount' => (int) $summaryQuery->sum('amount'),
            'count' => (int) $summaryQuery->count(),
        ];

        $perPage = min(50, max(1, (int) ($filters['per_page'] ?? 20)));
        $page = max(1, (int) ($filters['page'] ?? 1));
        $expenses = $query->orderByDesc('spent_at')->orderByDesc('id')->paginate($perPage, ['*'], 'page', $page);

        return ['expenses' => $expenses, 'summary' => $summary];
    }

    /** @return list<string> */
    public function suggestions(?string $search = null): array
    {
        $query = Expense::query()->select(['id', 'name', 'created_at']);

        if ($search !== null && $search !== '') {
            $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search);
            $query->whereRaw("LOWER(name) LIKE LOWER(?) ESCAPE '!'", ['%'.$escaped.'%']);
        }

        $groups = [];
        foreach ($query->orderByDesc('created_at')->orderByDesc('id')->get() as $expense) {
            $key = Str::lower(trim($expense->name));
            if ($key === '') {
                continue;
            }

            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'name' => $expense->name,
                    'count' => 0,
                    'latest_index' => count($groups),
                ];
            }
            $groups[$key]['count']++;
        }

        uasort($groups, static fn (array $left, array $right): int =>
            ($right['count'] <=> $left['count']) ?: ($left['latest_index'] <=> $right['latest_index'])
        );

        return array_slice(array_column(array_values($groups), 'name'), 0, 20);
    }

    /** @return list<string> */
    public function warningsForName(string $name): array
    {
        if (Str::contains(Str::lower($name), ['kulak', 'bawang'])) {
            return ["Nama mengandung kata 'kulak' atau 'bawang'. Pembelian bawang dicatat di menu Kulak, bukan Pengeluaran."];
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Expense $expense, array $attributes, int $ownerId): Expense
    {
        return DB::transaction(function () use ($expense, $attributes, $ownerId): Expense {
            $expense = Expense::query()->lockForUpdate()->findOrFail($expense->id);
            $before = $this->snapshot($expense);
            $expense->update($attributes);
            $this->recordAudit('expense.updated', $expense, $ownerId, [
                'before' => $before,
                'after' => $this->snapshot($expense->fresh()),
            ]);

            return $expense->fresh()->load('creator');
        });
    }

    public function delete(Expense $expense, int $ownerId, ?string $reason): void
    {
        DB::transaction(function () use ($expense, $ownerId, $reason): void {
            $expense = Expense::query()->lockForUpdate()->findOrFail($expense->id);
            $payload = [
                ...$this->snapshot($expense),
                'user' => ['id' => $ownerId],
                'reason' => $reason,
            ];
            $expense->deleted_by = $ownerId;
            $expense->save();
            $expense->delete();
            $this->recordAudit('expense.deleted', $expense, $ownerId, $payload);
        });
    }

    /** @return array<string, mixed> */
    private function snapshot(Expense $expense): array
    {
        return [
            'id' => (int) $expense->id,
            'name' => $expense->name,
            'category' => $expense->category,
            'amount' => (int) $expense->amount,
            'spent_at' => $expense->spent_at?->toDateString(),
            'notes' => $expense->notes,
        ];
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
