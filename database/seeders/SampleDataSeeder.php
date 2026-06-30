<?php

namespace Database\Seeders;

use App\Models\AccountTransaction;
use App\Models\Bike;
use App\Models\BikeIntake as Intake;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\JobCard;
use App\Models\JobCardPart;
use App\Models\JobCardService;
use App\Models\Product;
use App\Models\ProductCategory as Category;
use App\Models\Role;
use App\Models\StockBatch;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\Technician;
use App\Models\User;
use App\Models\Account;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Carbon\Carbon;

class SampleDataSeeder extends Seeder
{
    public function run(): void
    {
        $techRole = Role::where('name', 'Technician')->first();
        $adminRole = Role::where('name', 'Super Admin')->first();
        $store = Store::first();

        // 1. Users (Technicians)
        $tech1User = User::firstOrCreate(['email' => 'tech1@example.com'], ['name' => 'John Doe', 'username' => 'johndoe', 'password' => 'password', 'role_id' => $techRole->id]);
        $tech2User = User::firstOrCreate(['email' => 'tech2@example.com'], ['name' => 'Jane Smith', 'username' => 'janesmith', 'password' => 'password', 'role_id' => $techRole->id]);
        $admin = User::firstOrCreate(['email' => 'admin@example.com'], ['name' => 'Admin User', 'username' => 'adminuser', 'password' => 'password', 'role_id' => $adminRole->id]);

        $tech1 = Technician::firstOrCreate(['name' => 'John Doe'], ['phone' => '0711111111']);
        $tech2 = Technician::firstOrCreate(['name' => 'Jane Smith'], ['phone' => '0722222222']);

        // Accounts
        $mainAcc = Account::firstOrCreate(['code' => 'MAIN-01'], ['name' => 'Main Account', 'type' => 'Cash']);
        $partsAcc = Account::firstOrCreate(['code' => 'PARTS-01'], ['name' => 'Spare Parts', 'type' => 'Cash']);
        $bankAcc = Account::firstOrCreate(['code' => 'BANK-01'], ['name' => 'Bank Account', 'type' => 'Bank']);

        // 2. Categories & Products
        $catOils = Category::firstOrCreate(['name' => 'Engine Oils']);
        $catParts = Category::firstOrCreate(['name' => 'Spare Parts']);

        $oil = Product::firstOrCreate(['name' => 'Motul 20W50'], ['category_id' => $catOils->id, 'code' => 'MT-20W50', 'selling_price' => 2200]);
        $pad = Product::firstOrCreate(['name' => 'Honda Brake Pad'], ['category_id' => $catParts->id, 'code' => 'HN-BP01', 'selling_price' => 1500]);

        // 3. Stock Batches
        $oilBatch = StockBatch::firstOrCreate(['product_id' => $oil->id], [
            'store_id' => $store->id,
            'purchased_qty' => 20,
            'available_qty' => 20,
            'cost_price' => 1500,
            'selling_price' => 2200,
            'batch_no' => 'B-001',
            'purchase_date' => today(),
        ]);
        $padBatch = StockBatch::firstOrCreate(['product_id' => $pad->id], [
            'store_id' => $store->id,
            'purchased_qty' => 15,
            'available_qty' => 15,
            'cost_price' => 800,
            'selling_price' => 1500,
            'batch_no' => 'B-002',
            'purchase_date' => today(),
        ]);

        // 4. Customers & Bikes
        $customer1 = Customer::firstOrCreate(['phone' => '0771234567'], ['name' => 'Kamal Perera', 'email' => 'kamal@example.com']);
        $customer2 = Customer::firstOrCreate(['phone' => '0719876543'], ['name' => 'Nimal Fernando']);

        $bike1 = Bike::firstOrCreate(['number' => 'WP ABC-1234'], ['customer_id' => $customer1->id, 'brand' => 'Honda', 'model' => 'Dio']);
        $bike2 = Bike::firstOrCreate(['number' => 'SP XYZ-9876'], ['customer_id' => $customer2->id, 'brand' => 'Yamaha', 'model' => 'FZ']);

        // 5. Intakes & Job Cards (For the last 5 days)
        $dates = collect(range(0, 4))->map(fn($days) => today()->subDays($days))->reverse()->values();

        foreach ($dates as $index => $date) {
            // Intake
            $intake = Intake::firstOrCreate(['intake_number' => 'INT-'.$date->format('Ymd').'-'.($index + 1)], [
                'bike_id' => $bike1->id,
                'complaint' => 'General checkup and service',
                'fuel_level' => '50%',
                'current_mileage' => '15000',
                'created_by' => $admin->id,
                'created_at' => $date,
            ]);

            // Job Card
            $job = JobCard::firstOrCreate(['number' => 'JOB-'.$date->format('Ymd').'-'.($index + 1)], [
                'bike_intake_id' => $intake->id,
                'main_technician_id' => $tech1->id,
                'status' => 'Completed',
                'created_by' => $admin->id,
                'created_at' => $date,
            ]);

            // Job Card Parts
            JobCardPart::firstOrCreate(['job_card_id' => $job->id, 'product_id' => $oil->id], [
                'store_id' => $store->id,
                'stock_batch_id' => $oilBatch->id,
                'cost_price' => 1500,
                'selling_price' => 2200,
                'quantity' => 1,
                'unit_price' => 2200,
                'total' => 2200,
                'profit' => 700,
                'created_at' => $date
            ]);

            // Job Card Services
            JobCardService::firstOrCreate(['job_card_id' => $job->id, 'description' => 'Full Service Charge'], [
                'type' => 'Service',
                'amount' => 2500,
                'created_at' => $date
            ]);

            // Invoice for Job
            $invoice = Invoice::firstOrCreate(['number' => 'INV-'.$date->format('Ymd').'-'.($index + 1)], [
                'job_card_id' => $job->id,
                'customer_id' => $customer1->id,
                'bike_id' => $bike1->id,
                'subtotal' => 4700,
                'discount' => 200,
                'total' => 4500,
                'parts_amount' => 2200,
                'service_amount' => 2500,
                'paid_amount' => 4500,
                'due_amount' => 0,
                'payment_method' => 'Cash',
                'invoice_type' => 'Job Card Bill',
                'status' => 'final',
                'public_token' => Str::random(32),
                'store_id' => $store->id,
                'created_by' => $admin->id,
                'created_at' => $date,
            ]);

            // Invoice Items
            InvoiceItem::firstOrCreate(['invoice_id' => $invoice->id, 'product_id' => $oil->id], [
                'store_id' => $store->id,
                'quantity' => 1,
                'unit_price' => 2200,
                'total' => 2200,
            ]);

            // Account Transaction (Income)
            AccountTransaction::firstOrCreate(['reference_id' => $invoice->id, 'reference_type' => Invoice::class], [
                'transaction_no' => 'TRN-' . time() . rand(100, 999),
                'account_type' => 'main',
                'account_id' => $mainAcc->id,
                'transaction_type' => 'income',
                'amount' => 4500,
                'credit' => 4500,
                'debit' => 0,
                'description' => 'Payment for Invoice ' . $invoice->number,
                'payment_method' => 'Cash',
                'transaction_date' => $date,
                'created_by' => $admin->id,
                'created_at' => $date
            ]);
        }

        // 6. Direct Spare Parts Sale (Multiple dates)
        foreach ($dates as $index => $date) {
            $saleInv = Invoice::firstOrCreate(['number' => 'INV-SP-' . $date->format('Ymd') . '-1'], [
                'customer_id' => $customer2->id,
                'subtotal' => 1500,
                'total' => 1500,
                'parts_amount' => 1500,
                'paid_amount' => 1500,
                'due_amount' => 0,
                'payment_method' => 'Card',
                'invoice_type' => 'Parts Sale',
                'status' => 'final',
                'public_token' => Str::random(32),
                'store_id' => $store->id,
                'created_by' => $admin->id,
                'created_at' => $date
            ]);
            InvoiceItem::firstOrCreate(['invoice_id' => $saleInv->id, 'product_id' => $pad->id], [
                'store_id' => $store->id,
                'quantity' => 1,
                'unit_price' => 1500,
                'total' => 1500
            ]);
            AccountTransaction::firstOrCreate(['reference_id' => $saleInv->id, 'reference_type' => Invoice::class], [
                'transaction_no' => 'TRN-' . $date->timestamp . rand(100, 999),
                'account_type' => 'spare_parts',
                'account_id' => $partsAcc->id,
                'transaction_type' => 'income',
                'amount' => 1500,
                'credit' => 1500,
                'debit' => 0,
                'description' => 'Payment for Spare Parts Sale ' . $saleInv->number,
                'payment_method' => 'Card',
                'transaction_date' => $date,
                'created_by' => $admin->id,
                'created_at' => $date
            ]);

            // Add a bank transfer for spare parts (from parts to bank)
            AccountTransaction::firstOrCreate(['account_id' => $partsAcc->id, 'transaction_type' => 'transfer_out', 'amount' => 1000, 'transaction_date' => $date], [
                'transaction_no' => 'TRN-' . $date->timestamp . rand(100, 999),
                'account_type' => 'spare_parts',
                'debit' => 1000,
                'credit' => 0,
                'description' => 'Transfer to Bank',
                'payment_method' => 'Transfer',
                'created_by' => $admin->id,
                'created_at' => $date
            ]);
            AccountTransaction::firstOrCreate(['account_id' => $bankAcc->id, 'transaction_type' => 'transfer_in', 'amount' => 1000, 'transaction_date' => $date], [
                'transaction_no' => 'TRN-' . $date->timestamp . rand(100, 999),
                'account_type' => 'bank',
                'debit' => 0,
                'credit' => 1000,
                'description' => 'Transfer from Spare Parts',
                'payment_method' => 'Transfer',
                'created_by' => $admin->id,
                'created_at' => $date
            ]);
        }

        // 7. Expenses
        $expense = Expense::firstOrCreate(['description' => 'Electricity Bill', 'amount' => 500, 'expense_date' => today()], [
            'number' => 'EXP-' . time() . rand(10, 99),
            'category' => 'Utility Bills',
            'payment_method' => 'Cash',
            'account_id' => $mainAcc->id,
            'status' => 'Paid',
            'created_by' => $admin->id
        ]);
        AccountTransaction::firstOrCreate(['reference_id' => $expense->id, 'reference_type' => Expense::class], [
            'transaction_no' => 'TRN-' . time() . rand(100, 999),
            'account_type' => 'main',
            'account_id' => $mainAcc->id,
            'transaction_type' => 'expense',
            'amount' => 500,
            'debit' => 500,
            'credit' => 0,
            'description' => 'Electricity Bill',
            'payment_method' => 'Cash',
            'transaction_date' => today(),
            'created_by' => $admin->id,
            'created_at' => today()
        ]);

        $this->command->info('Sample data seeded successfully!');
    }
}
