<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SmsTemplate extends Model
{
    protected $guarded = [];

    protected $casts = [
        'auto_send' => 'boolean',
        'enabled' => 'boolean',
    ];

    public static array $categories = [
        'Bike Intake',
        'Job Assignment',
        'Job Status',
        'Bill / Invoice',
        'Payment',
        'Due Reminder',
        'Service Reminder',
        'Technician Salary',
        'Manual SMS',
    ];

    public static array $variables = [
        '{customer_name}',
        '{bike_number}',
        '{bike_model}',
        '{job_card_no}',
        '{invoice_no}',
        '{amount}',
        '{due}',
        '{bill_link}',
        '{business_name}',
        '{phone}',
        '{complaint}',
        '{next_service_date}',
    ];

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
