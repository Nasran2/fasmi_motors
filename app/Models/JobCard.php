<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/** @property CarbonInterface|null $bill_date
 * @property int $jobs_count
 * @property float|int $billed_total
 * @property-read BikeIntake $intake
 * @property-read Invoice|null $invoice
 */
class JobCard extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected $with = ['quickCustomer', 'quickBike'];

    protected $attributes = ['show_previous_balance_on_bill' => true, 'discount_percentage' => 0, 'fixed_discount' => 0];

    protected function casts(): array
    {
        return ['bill_date' => 'date', 'show_previous_balance_on_bill' => 'boolean', 'is_quick_job' => 'boolean', 'is_staff_bike' => 'boolean', 'quick_bike_details' => 'array', 'main_account_charges' => 'array'];
    }

    protected static function booted(): void
    {
        static::saving(function (JobCard $job) {
            if ($job->isDirty('main_account_charges')) {
                $job->main_account_charge_total = round(array_sum(array_column($job->main_account_charges ?? [], 'amount')), 2);
            }

            if (! $job->isDirty('discount_percentage') && ! $job->isDirty('fixed_discount')) {
                // Preserve existing callers that submit one discount type or a fixed amount.
                if ($job->isDirty('discount_value') || $job->isDirty('discount_type')) {
                    $job->discount_percentage = $job->discount_type === 'percentage' ? (float) $job->discount_value : 0;
                    $job->fixed_discount = $job->discount_type === 'percentage' ? 0 : (float) $job->discount_value;
                } elseif ($job->isDirty('discount')) {
                    $job->discount_percentage = 0;
                    $job->fixed_discount = (float) $job->discount;
                }
            }

            $job->discount = round($job->percentage_discount_amount + (float) $job->fixed_discount, 2);
            $job->discount_type = (float) $job->discount_percentage > 0 ? 'percentage' : 'fixed';
            $job->discount_value = (float) $job->discount_percentage > 0 ? $job->discount_percentage : $job->fixed_discount;
        });
    }

    public function getPercentageDiscountAmountAttribute(): float
    {
        return round($this->subtotal * (float) $this->discount_percentage / 100, 2);
    }

    public function getSubtotalAttribute(): float
    {
        return (float) $this->parts_total + $this->invoice_service_amount + (float) $this->labor_total + (float) $this->washing_total + (float) $this->other_total;
    }

    public function getInvoiceServiceAmountAttribute(): float
    {
        return round((float) $this->service_total + (float) $this->main_account_charge_total, 2);
    }

    public function getServiceLaborCommissionBaseAttribute(): float
    {
        return round((float) $this->service_total + (float) $this->labor_total, 2);
    }

    public function intake(): BelongsTo
    {
        return $this->belongsTo(BikeIntake::class, 'bike_intake_id');
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(Technician::class, 'main_technician_id');
    }

    public function washingPerson(): BelongsTo
    {
        return $this->belongsTo(Technician::class, 'washing_person_id')->withTrashed();
    }

    public function quickCustomer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'quick_customer_id');
    }

    public function quickBike(): BelongsTo
    {
        return $this->belongsTo(Bike::class, 'quick_bike_id');
    }

    /** @return BelongsTo<Technician, $this> */
    public function staffBikeOwner(): BelongsTo
    {
        return $this->belongsTo(Technician::class, 'staff_bike_owner_id');
    }

    public function getRepairStaffAttribute(): ?Technician
    {
        return $this->is_staff_bike ? $this->staffBikeOwner : ($this->bike?->is_technician_bike ? $this->bike->technician : null);
    }

    public function bikeDeductionPlans(): HasMany
    {
        return $this->hasMany(TechnicianBikeDeduction::class);
    }

    public function getBikeAttribute(): ?Bike
    {
        if (! $this->is_quick_job) {
            return $this->intake?->bike;
        }

        return $this->quickBike ?? (array_filter($this->quick_bike_details ?? []) ? new Bike($this->quick_bike_details) : null);
    }

    public function getCustomerAttribute(): ?Customer
    {
        return $this->is_quick_job ? $this->quickCustomer : $this->intake?->bike?->customer;
    }

    public function getCustomerIdAttribute(): ?int
    {
        return $this->is_quick_job ? $this->quick_customer_id : $this->intake?->bike?->customer_id;
    }

    public function getComplaintAttribute(): string
    {
        return $this->is_quick_job ? ($this->quick_complaint ?: 'Quick job') : ($this->intake?->complaint ?? '');
    }

    /** @return HasMany<JobCardPart, $this> */
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
