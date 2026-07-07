<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalaryPaymentAddition extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['addition_date' => 'date'];
    }
}
