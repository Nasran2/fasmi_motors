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
    use \App\Traits\ShopScoped;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['link_expires_at' => 'datetime', 'due_date' => 'date', 'last_opened_at' => 'datetime', 'sms_sent_at' => 'datetime', 'cancelled_at' => 'datetime', 'link_enabled' => 'boolean'];
    }

    public function jobCard(): BelongsTo
    {
        return $this->belongsTo(JobCard::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function bike(): BelongsTo
    {
        return $this->belongsTo(Bike::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function accesses(): HasMany
    {
        return $this->hasMany(PublicBillAccess::class);
    }
}
