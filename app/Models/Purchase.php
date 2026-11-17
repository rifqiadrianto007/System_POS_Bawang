<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Purchase extends Model
{
    use SoftDeletes;

    protected $fillable = ['code', 'supplier_id', 'purchased_at', 'jenis', 'gross_weight_kg', 'price_per_kg', 'total_cost', 'notes', 'created_by', 'sorted_finished_at', 'sorted_finished_by', 'cleaning_shrink_kg', 'cleaning_loss_value', 'needs_stock_reconciliation'];

    protected function casts(): array
    {
        return [
            'purchased_at' => 'date',
            'gross_weight_kg' => 'decimal:3',
            'sorted_finished_at' => 'datetime',
            'cleaning_shrink_kg' => 'decimal:3',
            'cleaning_loss_value' => 'integer',
            'needs_stock_reconciliation' => 'boolean',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function stockLots(): HasMany
    {
        return $this->hasMany(StockLot::class);
    }

    public function stockAdditions(): HasMany
    {
        return $this->hasMany(StockAddition::class);
    }
}
