<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'price_retail' => $this->price_retail,
            'price_3kg' => $this->price_3kg,
            'price_5kg' => $this->price_5kg,
            'is_active' => $this->is_active,
        ];
    }
}
