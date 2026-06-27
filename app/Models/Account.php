<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Account extends Model
{
    protected $guarded = [];

    public function transactions()
    {
        return $this->hasMany(AccountTransaction::class);
    }

    public function getBalanceAttribute(): float
    {
        return (float) $this->opening_balance + (float) $this->transactions()->sum('credit') - (float) $this->transactions()->sum('debit');
    }
}
