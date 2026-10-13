<?php

namespace App\Services;

use App\Models\Bike;
use App\Models\Customer;
use App\Models\Invoice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class OpeningBalanceService
{
    public static function rules(): array
    {
        return [
            'opening_balance' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:999999999999.99'],
            'opening_balance_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
        ];
    }

    public function record(Customer $customer, array $data, ?Bike $bike = null): ?Invoice
    {
        $amount = (float) ($data['opening_balance'] ?? 0);
        if ($amount <= 0) {
            return null;
        }
        $date = Carbon::parse($data['opening_balance_date'] ?? today())->startOfDay();

        return Invoice::create([
            'number' => 'OP-'.$date->format('ymd').'-'.Str::upper(Str::random(8)),
            'invoice_type' => 'Opening Due', 'shop_type' => $customer->shop_type,
            'customer_id' => $customer->id, 'bike_id' => $bike?->id,
            'subtotal' => $amount, 'total' => $amount, 'due_amount' => $amount, 'paid_amount' => 0,
            'status' => 'Unpaid', 'public_token' => Str::random(48),
            'internal_note' => 'Opening balance (previous / old balance). Collections belong to the Main Account.',
            'created_at' => $date, 'updated_at' => now(), 'created_by' => auth()->id(),
        ]);
    }
}
