<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'product_id' => $this->resource['product_id'],
            'name' => $this->resource['name'],
            'jenis' => $this->resource['jenis'],
            'is_active' => $this->resource['is_active'],
            'stock_kg' => $this->resource['stock_kg'],
            'shortfall_kg' => $this->resource['shortfall_kg'],
            'stock_value' => $this->resource['stock_value'],
            'avg_cost' => $this->resource['avg_cost'] ?? 0,
            'last_count_date' => $this->resource['last_count_date'],
        ];
    }
}
