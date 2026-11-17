<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockAddition extends Model
{
    protected $fillable = ['purchase_id', 'product_id', 'weight_kg', 'added_at', 'created_by'];

    protected function casts(): array
    {
        return ['weight_kg' => 'decimal:3', 'added_at' => 'date'];
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
