<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransactionReceiptResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = [
            'invoice_no' => $this->invoice_no,
            'occurred_at' => $this->occurred_at?->copy()->setTimezone('Asia/Jakarta')->toIso8601String(),
            'cashier' => $this->cashier?->name,
            'customer_name' => $this->customer_name,
            'items' => TransactionItemResource::collection($this->whenLoaded('items')),
            'subtotal' => $this->subtotal,
            'discount' => $this->discount,
            'total' => $this->total,
            'amount_paid' => $this->amount_paid,
            'change' => $this->amount_paid === null ? null : $this->amount_paid - $this->total,
            'payment_method' => $this->payment_method,
            'status' => $this->status,
        ];

        if ($request->user()?->role === 'owner') {
            $data['cogs'] = $this->items->sum('cogs');
        }

        return $data;
    }
}
