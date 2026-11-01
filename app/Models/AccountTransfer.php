<?php

namespace App\Models;

use App\Services\OutgoingFundingService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class AccountTransfer extends Model
{
    protected $guarded = [];

    protected $casts = ['transfer_date' => 'datetime', 'amount' => 'decimal:2', 'cancelled_at' => 'datetime'];

    public function fromAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'from_account_id');
    }

    public function toAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'to_account_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function transactions(): MorphMany
    {
        return $this->morphMany(AccountTransaction::class, 'reference');
    }

    public function getSourceLabelAttribute(): string
    {
        return $this->transactions->where('transaction_type', 'transfer_out')
            ->map(fn ($entry) => OutgoingFundingService::SOURCES[$entry->funding_source] ?? $this->fromAccount?->name)
            ->filter()->unique()->implode(' + ') ?: ($this->fromAccount?->name ?? '—');
    }

    public function getDestinationLabelAttribute(): string
    {
        $source = $this->transactions->firstWhere('transaction_type', 'transfer_in')?->funding_source;

        return OutgoingFundingService::SOURCES[$source] ?? $this->toAccount?->name ?? '—';
    }
}
