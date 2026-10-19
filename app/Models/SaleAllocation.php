<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaleAllocation extends Model
{
    protected $fillable = ['transaction_item_id', 'lot_id', 'weight_kg', 'unit_cost', 'is_shortfall'];

    protected function casts(): array
    {
        return ['is_shortfall' => 'boolean'];
    }

    public function transactionItem(): BelongsTo
    {
        return $this->belongsTo(TransactionItem::class);
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(StockLot::class, 'lot_id');
    }
}
