<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransactionHistoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'invoice_no' => $this->invoice_no,
            'occurred_at' => $this->occurred_at?->toIso8601String(),
            'cashier_name' => $this->cashier?->name,
            'items_count' => (int) $this->items_count,
            'total_weight_kg' => $this->formatWeight((string) ($this->items_sum_weight_kg ?? '0')),
            'subtotal' => (int) $this->subtotal,
            'discount' => (int) $this->discount,
            'total' => (int) $this->total,
            'payment_method' => $this->payment_method,
            'status' => $this->status,
            'customer_name' => $this->customer_name,
        ];
    }

    private function formatWeight(string $weight): string
    {
        $weight = trim($weight);
        $negative = str_starts_with($weight, '-');
        $weight = ltrim($weight, '+-');
        [$whole, $fraction] = array_pad(explode('.', $weight, 2), 2, '');
        $formatted = ltrim($whole, '0');
        $formatted = $formatted === '' ? '0' : $formatted;
        $formatted .= '.'.str_pad(substr($fraction, 0, 3), 3, '0');

        return $negative ? '-'.$formatted : $formatted;
    }
}
