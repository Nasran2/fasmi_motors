<?php

namespace App\Models;

use App\Traits\ShopScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Cheque extends Model
{
    use ShopScoped;

    protected $guarded = [];

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /** @return HasMany<SupplierPayment, $this> */
    public function supplierPayments(): HasMany
    {
        return $this->hasMany(SupplierPayment::class);
    }

    /** @return BelongsTo<Supplier, $this> */
    public function forwardedSupplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'forwarded_supplier_id');
    }

    protected $casts = [
        'cheque_date' => 'date',
        'realized_date' => 'date',
        'amount' => 'decimal:2',
    ];

    public function party(): MorphTo
    {
        return $this->morphTo();
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'bank_account_id');
    }
}
