<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/** @property Carbon|null $link_expires_at
 *  @property-read JobCard $jobCard
 */
class Invoice extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['link_expires_at' => 'datetime'];
    }

    public function jobCard(): BelongsTo
    {
        return $this->belongsTo(JobCard::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
