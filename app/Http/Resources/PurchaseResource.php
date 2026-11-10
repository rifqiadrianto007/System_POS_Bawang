<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id, 'code' => $this->code,
            'supplier' => ['id' => (int) $this->supplier->id, 'name' => $this->supplier->name],
            'purchased_at' => $this->purchased_at->format('Y-m-d'), 'jenis' => $this->jenis,
            'gross_weight_kg' => (string) $this->gross_weight_kg, 'price_per_kg' => (int) $this->price_per_kg,
            'total_cost' => (int) ($this->total_cost ?? 0), 'sorted_weight_kg' => $this->sorted_weight_kg,
            'remaining_weight_kg' => $this->remaining_weight_kg, 'sort_percentage' => (int) $this->sort_percentage,
            'status_sortir' => $this->status_sortir, 'can_edit' => (bool) $this->can_edit,
            'can_delete' => (bool) $this->can_delete, 'notes' => $this->notes ?? '', 'lots' => $this->lots ?? [],
        ];
    }
}
