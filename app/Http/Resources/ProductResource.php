<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = [
            'id' => $this->id,
            'name' => $this->name,
            'price_retail' => $this->price_retail,
            'price_3kg' => $this->price_3kg,
            'price_5kg' => $this->price_5kg,
            'is_active' => $this->is_active,
        ];

        if ($request->user()?->role === 'owner' && array_key_exists('stock_kg', $this->resource->getAttributes())) {
            $data['stock_kg'] = $this->stock_kg;
            $data['stock_value'] = $this->stock_value;
        }

        return $data;
    }
}
