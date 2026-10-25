<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Purchase extends Model
{
    protected $fillable = ['supplier_id', 'purchased_at', 'gross_weight_kg', 'price_per_kg', 'total_cost', 'notes', 'status', 'cost_per_clean_kg', 'clean_weight_kg', 'finalized_at', 'created_by'];

    protected function casts(): array
    {
        return [
            'purchased_at' => 'date',
            'gross_weight_kg' => 'decimal:3',
            'clean_weight_kg' => 'decimal:3',
            'finalized_at' => 'datetime',
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
}
