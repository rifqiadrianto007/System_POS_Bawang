<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TransactionItem extends Model
{
    protected $fillable = ['transaction_id', 'product_id', 'product_name', 'weight_kg', 'price_tier', 'price_per_kg', 'line_total', 'cogs'];

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function saleAllocations(): HasMany
    {
        return $this->hasMany(SaleAllocation::class);
    }
}
