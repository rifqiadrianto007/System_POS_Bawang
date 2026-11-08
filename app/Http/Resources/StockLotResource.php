<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockLotResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => (int) $this->id, 'purchase_id' => (int) $this->purchase_id, 'product_id' => (int) $this->product_id,
            'product_name' => $this->product->name, 'weight_kg' => (string) $this->weight_kg,
            'sorted_at' => $this->sorted_at->format('Y-m-d'), 'unit_cost' => (int) $this->unit_cost];
    }
}
