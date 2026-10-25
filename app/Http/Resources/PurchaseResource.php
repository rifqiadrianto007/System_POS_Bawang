<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'supplier_id' => $this->supplier_id,
            'supplier' => new SupplierResource($this->whenLoaded('supplier')),
            'purchased_at' => $this->purchased_at?->format('Y-m-d'),
            'gross_weight_kg' => $this->formatWeight($this->gross_weight_kg),
            'price_per_kg' => $this->price_per_kg,
            'total_cost' => $this->total_cost,
            'notes' => $this->notes,
            'status' => $this->status,
            'cost_per_clean_kg' => $this->cost_per_clean_kg,
            'clean_weight_kg' => $this->formatNullableWeight($this->clean_weight_kg),
            'finalized_at' => $this->finalized_at?->toIso8601String(),
            'created_by' => $this->created_by,
            'stock_lots' => StockLotResource::collection($this->whenLoaded('stockLots')),
        ];
    }

    private function formatNullableWeight(mixed $weight): ?string
    {
        return $weight === null ? null : $this->formatWeight($weight);
    }

    private function formatWeight(mixed $weight): string
    {
        $value = (string) $weight;
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');

        return $whole.'.'.str_pad(substr($fraction, 0, 3), 3, '0');
    }
}
