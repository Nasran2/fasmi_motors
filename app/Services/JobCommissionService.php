<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\JobCard;
use App\Models\TechnicianCommission;

class JobCommissionService
{
    public const TYPE = 'Job Card - Service / labor';

    public function syncPending(JobCard $job): void
    {
        if ($job->status === 'Cancelled' || $job->invoice?->status === 'Cancelled') {
            return;
        }
        $base = $job->service_labor_commission_base;
        $records = TechnicianCommission::where('job_card_id', $job->id)->where('status', 'Pending')
            ->whereNull('salary_sheet_id')->where('commission_type', 'like', 'Job Card - %')
            ->whereNotIn('commission_type', ['Job Card - Parts', WashingCommissionService::TYPE])->lockForUpdate()->get();
        foreach ($records as $record) {
            $values = ['base_amount' => $base, 'amount' => round($base * (float) $record->percentage / 100, 2), 'commission_type' => self::TYPE];
            if ((float) $record->base_amount === $values['base_amount'] && (float) $record->amount === $values['amount'] && $record->commission_type === self::TYPE) {
                continue;
            }
            $old = $record->only(['base_amount', 'amount', 'commission_type']);
            $record->update($values);
            AuditLog::create([
                'user_id' => auth()->id(), 'event' => 'commission_basis_corrected',
                'auditable_type' => TechnicianCommission::class, 'auditable_id' => $record->id,
                'old_values' => $old, 'new_values' => $values,
            ]);
        }
    }
}
