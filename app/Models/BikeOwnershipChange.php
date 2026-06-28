<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BikeOwnershipChange extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['changed_at' => 'date'];
    }

    public function bike(): BelongsTo
    {
        return $this->belongsTo(Bike::class);
    }

    public function oldCustomer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'old_customer_id');
    }

    public function newCustomer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'new_customer_id');
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
