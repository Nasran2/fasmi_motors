<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('salary_sheet_id')->nullable()->constrained('technician_salary_sheets')->nullOnDelete();
            $table->foreignId('technician_bike_deduction_id')->nullable()->constrained('technician_bike_deductions')->nullOnDelete();
        });
        DB::table('payments')->where('method', 'Salary Deduction')->whereNotNull('reference')->orderBy('id')->chunkById(200, function ($payments) {
            foreach ($payments as $payment) {
                if (! preg_match('/^salary:(\d+):plan:(\d+)$/', $payment->reference, $ids)) {
                    continue;
                }
                $plan = DB::table('technician_bike_deductions')->find($ids[2]);
                $sheet = DB::table('technician_salary_sheets')->find($ids[1]);
                if ($plan && $sheet && (int) $plan->invoice_id === (int) $payment->invoice_id && (int) $plan->technician_id === (int) $sheet->technician_id) {
                    DB::table('payments')->where('id', $payment->id)->update(['salary_sheet_id' => $sheet->id, 'technician_bike_deduction_id' => $plan->id, 'received_amount' => 0]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('salary_sheet_id');
            $table->dropConstrainedForeignId('technician_bike_deduction_id');
        });
    }
};
