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
            'product_name' => $this->resource['product_name'],
            'stock_weight_kg' => $this->resource['stock_weight_kg'],
            'inventory_value' => $this->resource['inventory_value'],
            'lots' => $this->resource['lots'],
        ];
    }
}
