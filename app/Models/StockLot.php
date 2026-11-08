<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class StockLot extends Model
{
    use SoftDeletes;

    protected $fillable = ['purchase_id', 'product_id', 'weight_kg', 'sorted_at', 'unit_cost', 'notes', 'created_by'];

    protected function casts(): array
    {
        return [
            'weight_kg' => 'decimal:3',
            'sorted_at' => 'date',
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
}
