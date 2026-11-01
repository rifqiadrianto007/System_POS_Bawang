<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'supplier' => ['id' => (int) $this->supplier->id, 'name' => $this->supplier->name],
            'purchased_at' => $this->purchased_at?->format('Y-m-d'),
            'gross_weight_kg' => (string) $this->gross_weight_kg,
            'price_per_kg' => (int) $this->price_per_kg,
            'total_cost' => (int) $this->total_cost,
            'status' => $this->status,
            'editable' => (bool) $this->editable,
            'lots_count' => (int) $this->lots_count,
            'lots_total_kg' => $this->lots_total_kg,
            'cleaning_shrink_kg' => $this->cleaning_shrink_kg,
            'cost_per_clean_kg' => $this->cost_per_clean_kg === null ? null : (int) $this->cost_per_clean_kg,
            'notes' => $this->notes,
            'lots' => StockLotResource::collection($this->whenLoaded('stockLots')),
        ];
    }
}
