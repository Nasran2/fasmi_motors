<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/** @property int $jobs_count
 * @property float|int $billed_total
 * @property-read BikeIntake $intake
 */
class JobCard extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::saving(function (JobCard $job) {
            if ($job->discount_type === 'percentage') {
                $job->discount = round($job->subtotal * (float) $job->discount_value / 100, 2);
            } elseif ($job->isDirty('discount_value') || $job->isDirty('discount_type')) {
                $job->discount = (float) $job->discount_value;
            } elseif ($job->isDirty('discount')) {
                // Preserve callers that still submit a fixed discount amount.
                $job->discount_value = $job->discount;
            }
        });
    }

    public function getSubtotalAttribute(): float
    {
        return (float) $this->parts_total + (float) $this->service_total + (float) $this->labor_total + (float) $this->washing_total + (float) $this->other_total;
    }

    public function intake(): BelongsTo
    {
        return $this->belongsTo(BikeIntake::class, 'bike_intake_id');
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(Technician::class, 'main_technician_id');
    }

    public function parts(): HasMany
    {
        return $this->hasMany(JobCardPart::class);
    }

    public function services(): HasMany
    {
        return $this->hasMany(JobCardService::class);
    }

    /** @return HasOne<Invoice, $this> */
    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class);
    }

    public function getTotalAttribute(): float
    {
        return max(0, round($this->subtotal - (float) $this->discount + (float) $this->tax, 2));
    }
}
