<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockCount extends Model
{
    protected $fillable = ['product_id', 'counted_weight_kg', 'system_weight_kg', 'diff_kg', 'unit_cost_avg', 'loss_value', 'counted_at', 'counted_by', 'notes'];

    protected function casts(): array
    {
        return ['counted_at' => 'datetime'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function countedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'counted_by');
    }
}
