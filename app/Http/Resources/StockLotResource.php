<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockLotResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'purchase_id' => $this->purchase_id,
            'product_id' => $this->product_id,
            'product' => new ProductResource($this->whenLoaded('product')),
            'clean_weight_kg' => $this->formatWeight($this->clean_weight_kg),
            'unit_cost' => $this->unit_cost,
            'cleaned_at' => $this->cleaned_at?->format('Y-m-d'),
            'notes' => $this->notes,
        ];
    }

    private function formatWeight(mixed $weight): string
    {
        $value = (string) $weight;
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');

        return $whole.'.'.str_pad(substr($fraction, 0, 3), 3, '0');
    }
}
