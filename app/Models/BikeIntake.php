<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** @property-read Bike $bike
 * @property-read JobCard|null $jobCard
 */
class BikeIntake extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_service' => 'boolean', 'estimated_delivery_date' => 'date', 'next_service_date' => 'date'];
    }

    public function bike(): BelongsTo
    {
        return $this->belongsTo(Bike::class);
    }

    public function jobCard(): HasOne
    {
        return $this->hasOne(JobCard::class);
    }
}
