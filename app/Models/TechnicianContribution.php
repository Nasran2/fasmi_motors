<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TechnicianContribution extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['month' => 'date', 'paid_at' => 'datetime'];
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(Technician::class);
    }

    public function salarySheet(): BelongsTo
    {
        return $this->belongsTo(TechnicianSalarySheet::class);
    }
}
