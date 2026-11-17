<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductBatchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'batch_id' => $this->resource['batch_id'],
            'batch_name' => $this->resource['batch_name'],
            'added_weight_kg' => $this->resource['added_weight_kg'],
        ];
    }
}
