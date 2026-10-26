<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JobAdvance extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'paid_at' => 'date', 'send_sms' => 'boolean'];
    }

    public function jobCard(): BelongsTo
    {
        return $this->belongsTo(JobCard::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function cheque(): BelongsTo
    {
        return $this->belongsTo(Cheque::class)->withTrashed();
    }
}
