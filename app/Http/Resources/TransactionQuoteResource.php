<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransactionQuoteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'items' => $this->resource['items'],
            'subtotal' => $this->resource['subtotal'],
            'discount' => $this->resource['discount'],
            'total' => $this->resource['total'],
        ];
    }
}
