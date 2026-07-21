<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Account extends Model
{
    use \App\Traits\ShopScoped;

    protected $guarded = [];

    public function transactions()
    {
        return $this->hasMany(AccountTransaction::class);
    }

    public function getBalanceAttribute(): float
    {
        return (float) $this->opening_balance
            + (float) $this->transactions()->where('status', 'active')->sum('credit')
            - (float) $this->transactions()->where('status', 'active')->sum('debit');
    }
}
