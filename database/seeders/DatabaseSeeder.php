<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $permissions = [
            'Admin' => ['dashboard.view', 'intake.view', 'intake.add', 'intake.edit', 'job_card.view', 'job_card.create', 'job_card.edit', 'job_card.change_status', 'job_card.add_parts', 'job_card.complete', 'customer.view', 'customer.add', 'customer.edit', 'bike.view', 'bike.add', 'bike.change_ownership', 'product.view', 'product.add', 'product.edit', 'product.import', 'product.categories', 'product.brands', 'product.units', 'product.stores', 'product.view_stock', 'product.transfer', 'product.adjustment', 'product.damage', 'product.view_low_stock', 'purchase.view', 'purchase.add', 'purchase.return', 'purchase.suppliers', 'purchase.supplier_payments', 'purchase.view_due', 'sales.job_card_billing', 'sales.parts_sales', 'sales.view_invoices', 'sales.receive_payments', 'sales.due_bills', 'sales.customer_payments', 'sales.public_links', 'sales.cancel_invoice', 'sales.apply_discount', 'technician.view', 'technician.add', 'technician.edit', 'technician.attendance', 'technician.salaries', 'technician.advances', 'technician.commission', 'technician.bike_deductions', 'technician.epf_etf', 'technician.history', 'account.view_main', 'account.view_spare_parts', 'account.view_bank', 'account.view_cash_book', 'account.transfers', 'account.daily_closing', 'account.cheques', 'account.bank_payments', 'expense.view', 'expense.add', 'drawing.view', 'drawing.add', 'reminder.view', 'reminder.send', 'reminder.edit', 'reminder.reschedule', 'sms.view', 'sms.send', 'sms.templates', 'sms.logs', 'sms.retry', 'reports.view', 'reports.export_pdf', 'reports.export_excel', 'reports.print'],
            'Manager' => ['dashboard.view', 'intake.view', 'job_card.view', 'job_card.change_status', 'customer.view', 'bike.view', 'product.view', 'product.view_stock', 'product.view_low_stock', 'purchase.view', 'purchase.view_due', 'sales.view_invoices', 'sales.due_bills', 'technician.view', 'technician.attendance', 'account.view_main', 'account.view_spare_parts', 'account.view_bank', 'account.view_cash_book', 'expense.view', 'drawing.view', 'reminder.view', 'sms.view', 'sms.logs', 'reports.view', 'reports.export_pdf', 'reports.export_excel', 'reports.print'],
            'Cashier' => ['dashboard.view', 'customer.view', 'customer.add', 'customer.view_due', 'sales.job_card_billing', 'sales.parts_sales', 'sales.view_invoices', 'sales.receive_payments', 'sales.due_bills', 'sales.customer_payments', 'sales.public_links', 'reports.view', 'reports.print'],
            'Store Keeper' => ['dashboard.view', 'product.view', 'product.add', 'product.edit', 'product.import', 'product.categories', 'product.brands', 'product.units', 'product.stores', 'product.view_stock', 'product.transfer', 'product.adjustment', 'product.damage', 'product.view_low_stock', 'purchase.view', 'purchase.add', 'purchase.return', 'purchase.suppliers', 'purchase.view_due', 'reports.view', 'reports.export_excel'],
            'Technician' => ['dashboard.view', 'intake.view', 'job_card.view', 'job_card.change_status', 'customer.view', 'bike.view', 'technician.view', 'technician.attendance', 'technician.history'],
            'Accountant' => ['dashboard.view', 'purchase.view', 'purchase.supplier_payments', 'purchase.view_due', 'sales.view_invoices', 'sales.receive_payments', 'sales.due_bills', 'sales.customer_payments', 'account.view_main', 'account.view_spare_parts', 'account.view_bank', 'account.view_cash_book', 'account.transfers', 'account.daily_closing', 'account.cheques', 'account.bank_payments', 'account.opening_balances', 'account.edit_ledger', 'account.adjustment', 'account.reopen_closing', 'expense.view', 'expense.add', 'expense.cancel', 'expense.categories', 'drawing.view', 'drawing.add', 'drawing.cancel', 'drawing.categories', 'reports.view', 'reports.export_pdf', 'reports.export_excel', 'reports.print'],
        ];
        foreach (['Admin', 'Accountant'] as $name) {
            $permissions[$name] = array_merge($permissions[$name], ['capital.view', 'capital.add', 'capital.edit', 'capital.delete']);
        }
        foreach (['Super Admin', 'Admin', 'Manager', 'Cashier', 'Store Keeper', 'Technician', 'Accountant'] as $name) {
            Role::updateOrCreate(['name' => $name], ['permissions' => $name === 'Super Admin' ? [] : $permissions[$name]]);
        }
        $admin = User::firstOrCreate(['email' => 'admin@fasmirmotors.lk'], ['name' => 'Fasmir Admin', 'username' => 'admin', 'password' => 'password', 'email_verified_at' => now()]);
        $admin->update(['username' => 'admin', 'role_id' => Role::where('name', 'Super Admin')->value('id')]);
        Store::firstOrCreate(['name' => 'Main Store'], ['is_default' => true, 'active' => true]);
        foreach ([['MAIN', 'Main Account', 'main'], ['SPARE', 'Spare Parts Account', 'spare_parts'], ['BANK', 'Bank Account', 'bank']] as [$code,$name,$type]) {
            Account::firstOrCreate(['code' => $code], ['name' => $name, 'type' => $type]);
        }
        foreach (['business_name' => 'Fasmir Motors Bike Repair Center', 'business_phone' => '+94 77 000 0000', 'business_address' => 'Sri Lanka', 'currency' => 'LKR', 'default_commission' => '10', 'epf_employee' => '8', 'epf_company' => '12', 'etf_company' => '3', 'default_reminder_days' => '60', 'negative_stock' => '0'] as $key => $value) {
            Setting::firstOrCreate(['key' => $key], ['group' => 'general', 'value' => $value]);
        }
    }
}
