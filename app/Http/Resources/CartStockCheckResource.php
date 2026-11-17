<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CartStockCheckResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['items' => $this->resource['items']];
    }
}
