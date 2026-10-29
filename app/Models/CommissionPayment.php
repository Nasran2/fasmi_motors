<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommissionPayment extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['payment_date' => 'date', 'amount' => 'decimal:2'];
    }

    public function items()
    {
        return $this->hasMany(CommissionPaymentItem::class);
    }

    public function account()
    {
        return $this->belongsTo(Account::class);
    }

    public function technician()
    {
        return $this->belongsTo(Technician::class);
    }
}
