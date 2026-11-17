<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id, 'code' => $this->code,
            'supplier' => ['id' => (int) $this->supplier->id, 'name' => $this->supplier->name],
            'purchased_at' => $this->purchased_at->format('Y-m-d'), 'jenis' => $this->jenis,
            'gross_weight_kg' => (string) $this->gross_weight_kg, 'price_per_kg' => (int) $this->price_per_kg,
            'total_cost' => (int) ($this->total_cost ?? 0), 'added_weight_kg' => $this->added_weight_kg,
            'remaining_weight_kg' => $this->remaining_weight_kg,
            'status_batch' => $this->status_batch, 'can_edit' => (bool) $this->can_edit,
            'can_delete' => (bool) $this->can_delete, 'is_locked' => (bool) $this->is_locked,
            'needs_stock_reconciliation' => (bool) $this->needs_stock_reconciliation,
            'cleaning_shrink_kg' => (string) $this->cleaning_shrink_kg,
            'cleaning_loss_value' => (int) $this->cleaning_loss_value,
            'sorted_finished_at' => $this->sorted_finished_at?->toIso8601String(),
            'notes' => $this->notes ?? '',
        ];
    }
}
