<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = [
            'id' => $this->id,
            'invoice_no' => $this->invoice_no,
            'client_uuid' => $this->client_uuid,
            'cashier_id' => $this->cashier_id,
            'customer_category' => $this->customer_category,
            'customer_name' => $this->customer_name,
            'subtotal' => $this->subtotal,
            'discount' => $this->discount,
            'total' => $this->total,
            'amount_paid' => $this->amount_paid,
            'change' => $this->amount_paid === null ? null : $this->amount_paid - $this->total,
            'payment_method' => $this->payment_method,
            'status' => $this->status,
            'occurred_at' => $this->occurred_at?->toIso8601String(),
            'void_reason' => $this->void_reason,
            'voided_by' => $this->voided_by,
            'voided_at' => $this->voided_at?->toIso8601String(),
            'items' => TransactionItemResource::collection($this->whenLoaded('items')),
        ];

        if ($request->user()?->role === 'owner') {
            $data['cogs'] = $this->items->sum('cogs');
        }

        return $data;
    }
}
