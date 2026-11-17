<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockAdditionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'batch_id' => (int) $this->purchase_id,
            'product_id' => (int) $this->product_id,
            'weight_kg' => (string) $this->weight_kg,
            'added_at' => $this->added_at->format('Y-m-d'),
            'user_id' => (int) $this->created_by,
        ];
    }
}
