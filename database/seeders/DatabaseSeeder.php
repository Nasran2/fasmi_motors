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
        $all = ['dashboard.view', 'intakes.manage', 'jobs.manage', 'stock.manage', 'billing.manage', 'accounts.view', 'accounts.manage', 'technicians.manage', 'reports.view', 'settings.manage'];
        foreach (['Super Admin', 'Admin', 'Manager', 'Cashier', 'Store Keeper', 'Technician', 'Accountant'] as $name) {
            Role::firstOrCreate(['name' => $name], ['permissions' => $name === 'Super Admin' ? $all : match ($name) {
                'Cashier' => ['dashboard.view', 'intakes.manage', 'billing.manage'],'Store Keeper' => ['dashboard.view', 'stock.manage'],'Technician' => ['dashboard.view', 'jobs.manage'],'Accountant' => ['dashboard.view', 'accounts.view', 'accounts.manage', 'reports.view'],default => array_slice($all, 0, 9)
            }]);
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
