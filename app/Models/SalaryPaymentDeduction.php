<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalaryPaymentDeduction extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['deduction_date' => 'date'];
    }
}
