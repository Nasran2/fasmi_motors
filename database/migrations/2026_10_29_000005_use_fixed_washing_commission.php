<?php

use App\Models\JobCard;
use App\Models\TechnicianCommission;
use App\Services\WashingCommissionService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['cashier_sessions', 'job_cards'] as $name) {
            if (! Schema::hasColumn($name, 'washing_commission_amount')) {
                Schema::table($name, fn (Blueprint $table) => $table->decimal('washing_commission_amount', 16, 2)->default(60));
            }
        }
        if (! Schema::hasColumn('cashier_session_events', 'washing_commission_amount')) {
            Schema::table('cashier_session_events', fn (Blueprint $table) => $table->decimal('washing_commission_amount', 16, 2)->nullable());
        }
        $previous = session('shop_mode', 'workshop');
        try {
            foreach (['workshop', 'helmet'] as $shop) {
                session(['shop_mode' => $shop]);
                JobCard::query()->whereHas('invoice', fn ($q) => $q->where('shop_type', $shop)->where('status', '!=', 'Cancelled'))
                    ->whereNotNull('washing_person_id')->where('washing_total', '>', 0)->each(function ($job) {
                        DB::transaction(function () use ($job) {
                            $job = JobCard::withoutGlobalScopes()->lockForUpdate()->findOrFail($job->id);
                            $finalized = TechnicianCommission::where('job_card_id', $job->id)->where('commission_type', WashingCommissionService::TYPE)
                                ->where(fn ($q) => $q->where('status', '!=', 'Pending')->orWhereNotNull('salary_sheet_id'))->first();
                            if ($finalized) {
                                // Preserve paid/payroll-linked earnings and their original percentage history.
                                $job->update(['washing_commission_amount' => $finalized->amount]);
                            } else {
                                app(WashingCommissionService::class)->sync($job);
                            }
                        });
                    });
            }
        } finally {
            session(['shop_mode' => $previous]);
        }
    }

    public function down(): void
    {
        foreach (['cashier_sessions', 'job_cards', 'cashier_session_events'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn('washing_commission_amount'));
        }
        // Commission corrections remain in the audit trail; reverting schema never rewrites paid earnings.
    }
};
