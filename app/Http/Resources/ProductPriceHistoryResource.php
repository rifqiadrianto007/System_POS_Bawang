<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductPriceHistoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'price_retail' => $this->price_retail,
            'price_3kg' => $this->price_3kg,
            'price_5kg' => $this->price_5kg,
            'changed_by' => $this->changed_by,
            'changed_at' => $this->changed_at?->toIso8601String(),
        ];
    }
}
