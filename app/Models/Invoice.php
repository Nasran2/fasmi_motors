<?php

namespace App\Models;

use App\Traits\ShopScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/** @property float|string $hold_amount
 * @property Carbon|null $link_expires_at
 * @property-read JobCard $jobCard
 */
class Invoice extends Model
{
    use ShopScoped;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['link_expires_at' => 'datetime', 'due_date' => 'date', 'last_opened_at' => 'datetime', 'sms_sent_at' => 'datetime', 'cancelled_at' => 'datetime', 'parts_deleted_at' => 'datetime', 'link_enabled' => 'boolean', 'is_accident_quotation' => 'boolean'];
    }

    public function jobCard(): BelongsTo
    {
        return $this->belongsTo(JobCard::class);
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /** @return BelongsTo<Customer, $this> */
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

    public function returns(): HasMany
    {
        return $this->hasMany(SaleReturn::class)->latest();
    }

    public function charges(): HasMany
    {
        return $this->hasMany(InvoiceCharge::class)->orderBy('sort_order')->orderBy('id');
    }

    public function sourceQuotation(): BelongsTo
    {
        return $this->belongsTo(self::class, 'source_quotation_id');
    }

    public function convertedInvoice(): HasOne
    {
        return $this->hasOne(self::class, 'source_quotation_id');
    }

    public function accesses(): HasMany
    {
        return $this->hasMany(PublicBillAccess::class);
    }
}
