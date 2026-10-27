<?php

namespace App\Http\Controllers;

use App\Http\Requests\TransactionIndexRequest;
use App\Http\Resources\TransactionHistoryResource;
use App\Models\Transaction;
use App\Support\BusinessDay;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Carbon\Carbon;

class TransactionHistoryController extends Controller
{
    public function __invoke(TransactionIndexRequest $request): JsonResponse
    {
        [$start, $end] = $this->requestedBounds($request);

        $query = Transaction::query()
            ->with(['cashier:id,name'])
            ->withCount('items')
            ->withSum('items', 'weight_kg');
        $this->applyBounds($query, $start, $end);

        $search = $request->input('q');
        if (is_string($search) && $search !== '') {
            $escaped = strtr($search, ['!' => '!!', '%' => '!%', '_' => '!_']);
            $query->whereRaw("invoice_no LIKE ? ESCAPE '!'", ['%'.$escaped.'%']);
        }

        $query->orderByDesc('occurred_at')->orderByDesc('id');
        $perPage = min($request->integer('per_page', 20), 50);
        $page = $query->paginate($perPage, ['*'], 'page', $request->integer('page', 1));

        $summaryQuery = Transaction::query();
        $this->applyBounds($summaryQuery, $start, $end);
        $summary = $summaryQuery->selectRaw(<<<'SQL'
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS transactions_count,
            SUM(CASE WHEN status = 'void' THEN 1 ELSE 0 END) AS voided_count,
            SUM(CASE WHEN status = 'completed' THEN subtotal ELSE 0 END) AS subtotal_sum,
            SUM(CASE WHEN status = 'completed' THEN discount ELSE 0 END) AS discount_sum,
            SUM(CASE WHEN status = 'completed' THEN total ELSE 0 END) AS total_sum
            SQL)->first();

        return response()->json([
            'data' => TransactionHistoryResource::collection($page->getCollection())->resolve($request),
            'meta' => [
                'total' => $page->total(),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
            ],
            'summary' => [
                'transactions_count' => (int) ($summary->transactions_count ?? 0),
                'voided_count' => (int) ($summary->voided_count ?? 0),
                'subtotal_sum' => (int) ($summary->subtotal_sum ?? 0),
                'discount_sum' => (int) ($summary->discount_sum ?? 0),
                'total_sum' => (int) ($summary->total_sum ?? 0),
            ],
        ]);
    }

    /** @return array{0: ?Carbon, 1: ?Carbon} */
    private function requestedBounds(TransactionIndexRequest $request): array
    {
        $period = $request->input('period');
        if ($period === 'today' || ($period === null && ! $request->filled('from') && ! $request->filled('to'))) {
            return BusinessDay::today();
        }
        if ($period === 'yesterday') {
            return BusinessDay::yesterday();
        }

        $from = $request->input('from');
        $to = $request->input('to');
        if (is_string($from) && is_string($to)) {
            return BusinessDay::between($from, $to);
        }

        $start = is_string($from) ? BusinessDay::forDate($from)[0] : null;
        $end = is_string($to) ? BusinessDay::forDate($to)[1] : null;

        return [$start, $end];
    }

    private function applyBounds(Builder $query, ?Carbon $start, ?Carbon $end): void
    {
        if ($start !== null) {
            $query->where('occurred_at', '>=', $start);
        }
        if ($end !== null) {
            $query->where('occurred_at', '<', $end);
        }
    }
}
