<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TechnicianDeduction extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['deduction_date' => 'date'];
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(Technician::class);
    }

    public function salarySheet(): BelongsTo
    {
        return $this->belongsTo(TechnicianSalarySheet::class, 'salary_payment_id');
    }
}
