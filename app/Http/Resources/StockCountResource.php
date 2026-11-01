<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockCountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'product_name' => $this->product?->name,
            'counted_weight_kg' => $this->formatWeight($this->counted_weight_kg),
            'system_weight_kg' => $this->formatWeight($this->system_weight_kg),
            'diff_kg' => $this->formatWeight($this->diff_kg),
            'unit_cost_avg' => $this->unit_cost_avg,
            'loss_value' => $this->loss_value,
            'counted_at' => $this->counted_at?->setTimezone('Asia/Jakarta')->toIso8601String(),
            'counted_by' => $this->counted_by,
            'notes' => $this->notes,
            'status' => $this->status ?? 'active',
            'cancelled_at' => $this->cancelled_at?->setTimezone('Asia/Jakarta')->toIso8601String(),
            'cancelled_by_name' => $this->cancelled_by_name,
            'cancel_reason' => $this->cancel_reason,
        ];
    }

    private function formatWeight(mixed $weight): string
    {
        $value = (string) $weight;
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');

        return $whole.'.'.str_pad(substr($fraction, 0, 3), 3, '0');
    }
}
