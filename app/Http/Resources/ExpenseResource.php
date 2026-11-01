<?php

namespace App\Http\Resources;

use App\Enums\ExpenseCategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExpenseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'category' => $this->category,
            'category_label' => ExpenseCategory::labelFor($this->category),
            'amount' => $this->amount,
            'spent_at' => $this->spent_at?->toDateString(),
            'notes' => $this->notes,
            'created_by_name' => $this->whenLoaded('creator', fn () => $this->creator?->name),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
