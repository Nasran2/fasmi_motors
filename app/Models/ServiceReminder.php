<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceReminder extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['due_date' => 'date', 'sent_at' => 'datetime'];
    }

    /** @return BelongsTo<Bike, $this> */
    public function bike(): BelongsTo
    {
        return $this->belongsTo(Bike::class);
    }

    /** @return BelongsTo<JobCard, $this> */
    public function jobCard(): BelongsTo
    {
        return $this->belongsTo(JobCard::class);
    }
}
