<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FinancialCategory extends Model
{
    protected $guarded = [];

    public function account()
    {
        return $this->belongsTo(Account::class, 'default_account_id');
    }
}
