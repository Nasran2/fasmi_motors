<?php

namespace App\Services;

use App\Models\CashierSession;
use App\Models\JobCard;
use App\Models\Setting;
use App\Models\Technician;
use App\Models\TechnicianCommission;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class WashingCommissionService
{
    public const TYPE = 'Job Card - Washing';

    public static function settingKey(): string
    {
        return 'washing_commission_percentage.'.session('shop_mode', 'workshop');
    }

    public function defaultPercentage(): float
    {
        return max(0, min(100, (float) Setting::valueFor(self::settingKey(), 10)));
    }

    public static function rules(): array
    {
        return ['washing_person_id' => ['sometimes', 'nullable', Rule::exists('technicians', 'id')->where('active', true)->whereNull('deleted_at')],
            'washing_commission_percentage' => 'sometimes|required|numeric|decimal:0,2|between:0,100'];
    }

    public function defaults(): array
    {
        $session = CashierSession::active();
        $person = $session?->washing_person_id;
        if ($person && ! Technician::whereKey($person)->where('active', true)->exists()) {
            $person = null;
        }

        return ['washing_person_id' => $person, 'washing_commission_percentage' => $session ? (float) $session->washing_commission_percentage : $this->defaultPercentage()];
    }

    public function sync(JobCard $job): void
    {
        $invoice = $job->invoice;
        if (! $invoice || $invoice->status === 'Cancelled' || $job->status === 'Cancelled') {
            return;
        }
        $records = TechnicianCommission::where('job_card_id', $job->id)->where('commission_type', self::TYPE)->lockForUpdate()->get();
        $base = round((float) $job->washing_total, 2);
        $percentage = (float) $job->washing_commission_percentage;
        $amount = round($base * $percentage / 100, 2);
        $eligible = $job->washing_person_id && $amount > 0;
        foreach ($records as $record) {
            if ($record->status !== 'Pending' || $record->salary_sheet_id !== null) {
                if (! $eligible || (int) $record->technician_id !== (int) $job->washing_person_id || (float) $record->base_amount !== $base || (float) $record->percentage !== $percentage) {
                    throw ValidationException::withMessages(['washing_person_id' => 'This washing commission is already included in payroll or paid. Resolve its payroll entry before changing the washing assignment or amount.']);
                }

                return;
            }
        }
        $record = $records->first();
        if (! $eligible) {
            $records->each->delete();

            return;
        }
        $values = ['technician_id' => $job->washing_person_id, 'invoice_id' => $invoice->id, 'base_amount' => $base,
            'percentage' => $percentage, 'amount' => $amount, 'earned_date' => $invoice->bill_date->toDateString()];
        if ($record) {
            $record->update($values);
        } else {
            TechnicianCommission::create($values + ['job_card_id' => $job->id, 'commission_type' => self::TYPE,
                'status' => 'Pending', 'created_by' => auth()->id()]);
        }
    }
}
