<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ResetDataCommand extends Command
{
    protected $signature = 'app:reset-data';
    protected $description = 'Resets all transactions and sets product stock to 0 while keeping core data.';

    public function handle()
    {
        if (!$this->confirm('Are you sure you want to delete all transactions and reset stock to 0? This cannot be undone!')) {
            return;
        }

        $tablesToTruncate = [
            'account_transactions',
            'account_transfers',
            'activity_logs',
            'attendance_import_batches',
            'audit_logs',
            'bike_intakes',
            'bike_ownership_changes',
            'cheques',
            'daily_closings',
            'drawings',
            'expenses',
            'sale_returns',
            'invoice_charges',
            'invoice_items',
            'invoices',
            'job_card_parts',
            'job_card_services',
            'job_card_status_logs',
            'job_card_technicians',
            'job_cards',
            'login_histories',
            'payments',
            'public_bill_accesses',
            'purchase_items',
            'purchase_returns',
            'purchases',
            'service_reminders',
            'sms_logs',
            'stock_batches',
            'stock_damage_reports',
            'stock_movements',
            'stock_transfers',
            'store_stocks',
            'supplier_payments',
            'technician_advances',
            'technician_attendance',
            'technician_bike_deductions',
            'technician_commissions',
            'technician_contributions',
            'technician_deductions',
            'technician_salary_sheets',
            'salary_payment_additions',
            'salary_payment_deductions',
            'technicians',
        ];

        DB::statement('SET FOREIGN_KEY_CHECKS=0;');

        foreach ($tablesToTruncate as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->truncate();
                $this->info("Truncated {$table}");
            }
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        $this->info('All transactional data has been deleted and product stock reset to 0.');
    }
}
