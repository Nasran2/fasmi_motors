<?php

namespace App\Models;

use App\Traits\ShopScoped;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** @property float|string $hold_amount
 * @property Carbon|null $link_expires_at
 * @property-read JobCard|null $jobCard
 */
class Invoice extends Model
{
    use ShopScoped;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['bill_date' => 'datetime', 'link_expires_at' => 'datetime', 'due_date' => 'date', 'last_opened_at' => 'datetime', 'sms_sent_at' => 'datetime', 'cancelled_at' => 'datetime', 'parts_deleted_at' => 'datetime', 'link_enabled' => 'boolean', 'is_accident_quotation' => 'boolean'];
    }

    public function getBillDateAttribute($value): ?Carbon
    {
        return $value ? Carbon::parse($value) : ($this->created_at ? Carbon::parse($this->created_at) : null);
    }

    public function scopeBillDatedBetween(Builder $query, array $dates): Builder
    {
        return $query->whereBetween(DB::raw('COALESCE(invoices.bill_date, invoices.created_at)'), $dates);
    }

    public function scopeBillDatedOn(Builder $query, $date, string $operator = '='): Builder
    {
        return $query->whereDate(DB::raw('COALESCE(invoices.bill_date, invoices.created_at)'), $operator, $date);
    }

    /** @return BelongsTo<JobCard, $this> */
    public function jobCard(): BelongsTo
    {
        return $this->belongsTo(JobCard::class);
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function isOpeningDue(): bool
    {
        return in_array($this->invoice_type, ['Opening Due', 'Opening Balance'], true);
    }

    /** @param Builder<Invoice> $query */
    public function scopeCustomerAccount(Builder $query, int $customerId): void
    {
        $query->where(fn (Builder $query) => $query->where('customer_id', $customerId)
            ->orWhere(fn (Builder $query) => $query->whereNull('customer_id')
                ->whereHas('jobCard.intake.bike', fn (Builder $bike) => $bike->where('customer_id', $customerId))));
    }

    public function getReceivedAmountAttribute(): float
    {
        return round($this->payments->whereIn('status', ['Completed', 'Hold'])->sum(fn ($payment) => (float) ($payment->received_amount ?? $payment->amount)), 2);
    }

    public function getChangeAmountAttribute(): float
    {
        return round($this->payments->whereIn('status', ['Completed', 'Hold'])->sum('change_amount'), 2);
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<Bike, $this> */
    public function bike(): BelongsTo
    {
        return $this->belongsTo(Bike::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /** @return HasMany<InvoiceItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    /** @return HasMany<SaleReturn, $this> */
    public function returns(): HasMany
    {
        return $this->hasMany(SaleReturn::class)->latest();
    }

    /** @return HasMany<InvoiceCharge, $this> */
    public function charges(): HasMany
    {
        return $this->hasMany(InvoiceCharge::class)->orderBy('sort_order')->orderBy('id');
    }

    /** @return BelongsTo<Invoice, $this> */
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
