<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;

class AuditLogService
{
    public function list(array $filters): LengthAwarePaginator
    {
        $query = AuditLog::query()
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if (isset($filters['action'])) {
            $query->where('action', $filters['action']);
        }

        if (isset($filters['from'])) {
            $query->where('created_at', '>=', Carbon::createFromFormat('Y-m-d', $filters['from'], 'Asia/Jakarta')->startOfDay()->utc());
        }

        if (isset($filters['to'])) {
            $query->where('created_at', '<=', Carbon::createFromFormat('Y-m-d', $filters['to'], 'Asia/Jakarta')->endOfDay()->utc());
        }

        return $query->paginate($filters['per_page'] ?? 20);
    }
}
