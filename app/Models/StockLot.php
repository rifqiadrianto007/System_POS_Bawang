<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockLot extends Model
{
    protected $fillable = ['purchase_id', 'product_id', 'clean_weight_kg', 'unit_cost', 'cleaned_at', 'notes'];

    protected function casts(): array
    {
        return [
            'clean_weight_kg' => 'decimal:3',
            'cleaned_at' => 'date',
        ];
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'lot_id');
    }

    public function saleAllocations(): HasMany
    {
        return $this->hasMany(SaleAllocation::class, 'lot_id');
    }
}
