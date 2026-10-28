<?php

use App\Models\JobCard;
use App\Models\TechnicianCommission;
use App\Services\JobCommissionService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        JobCard::withoutGlobalScopes()->whereIn('id', TechnicianCommission::where('status', 'Pending')
            ->whereNull('salary_sheet_id')->where('commission_type', 'like', 'Job Card - %')->select('job_card_id'))
            ->each(fn ($job) => DB::transaction(fn () => app(JobCommissionService::class)->syncPending($job)));
    }

    public function down(): void
    {
        // Each correction retains its previous values in the audit log. Payroll
        // already finalized after migration must not be rewritten on rollback.
    }
};
