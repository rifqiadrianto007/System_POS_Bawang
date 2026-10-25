<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransactionItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'product_name' => $this->product_name,
            'weight_kg' => $this->formatWeight($this->weight_kg),
            'price_tier' => $this->price_tier,
            'price_per_kg' => $this->price_per_kg,
            'line_total' => $this->line_total,
        ];

        if ($request->user()?->role === 'owner') {
            $data['cogs'] = $this->cogs;
        }

        return $data;
    }

    private function formatWeight(mixed $weight): string
    {
        $value = (string) $weight;
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');

        return $whole.'.'.str_pad(substr($fraction, 0, 3), 3, '0');
    }
}
