<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Purchase extends Model
{
    use SoftDeletes;

    protected $fillable = ['code', 'supplier_id', 'purchased_at', 'jenis', 'gross_weight_kg', 'price_per_kg', 'total_cost', 'notes', 'created_by'];

    protected function casts(): array
    {
        return [
            'purchased_at' => 'date',
            'gross_weight_kg' => 'decimal:3',
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
