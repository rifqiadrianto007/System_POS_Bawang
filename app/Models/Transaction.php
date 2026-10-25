<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Transaction extends Model
{
    protected $fillable = ['invoice_no', 'client_uuid', 'cashier_id', 'customer_category', 'customer_name', 'subtotal', 'discount', 'total', 'amount_paid', 'payment_method', 'status', 'occurred_at', 'void_reason', 'voided_by', 'voided_at'];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime', 'voided_at' => 'datetime'];
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(TransactionItem::class);
    }
}
