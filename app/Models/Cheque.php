<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Cheque extends Model
{
    protected $guarded = [];

    protected $casts = [
        'cheque_date' => 'date',
        'realized_date' => 'date',
        'amount' => 'decimal:2',
    ];

    public function party(): MorphTo
    {
        return $this->morphTo();
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'bank_account_id');
    }
}
