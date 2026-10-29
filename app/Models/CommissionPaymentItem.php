<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommissionPaymentItem extends Model
{
    protected $guarded = [];

    public function payment()
    {
        return $this->belongsTo(CommissionPayment::class, 'commission_payment_id');
    }
}
