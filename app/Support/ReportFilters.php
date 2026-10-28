<?php

namespace App\Support;

use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\Bike;
use App\Models\Customer;
use App\Models\Drawing;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\ProductCategory;
use App\Models\SmsLog;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\Technician;
use App\Models\User;

final class ReportFilters
{
    public static function fields(string $report): array
    {
        $fields = [];
        $select = function ($name, $label, $options, $searchable = false) use (&$fields) {
            $fields[] = compact('name', 'label', 'options', 'searchable');
        };
        if (in_array($report, ['index', 'job-cards', 'spare-parts', 'due-bills', 'customer-due', 'service-reminders', 'collections', 'sales-returns'])) {
            $select('bike_id', 'Bike number', Bike::withTrashed()->where(fn ($q) => $q->whereIn('customer_id', Customer::withTrashed()->select('id'))->orWhereHas('invoices')->when(session('shop_mode', 'workshop') === 'workshop', fn ($q) => $q->orWhere('is_technician_bike', true)))->orderBy('number')->pluck('number', 'id')->all(), true);
            $select('customer_id', 'Customer', Customer::withTrashed()->orderBy('name')->get()->mapWithKeys(fn ($c) => [$c->id => trim(($c->name ?: 'Customer').' · '.$c->phone.($c->trashed() ? ' (archived)' : ''))])->all(), true);
        }
        if (in_array($report, ['index', 'due-bills'])) {
            $types = array_values(array_unique(array_merge(['Job Card Bill', 'Parts Sale'], Invoice::whereNotIn('invoice_type', ['Quotation', 'Opening Due', 'Opening Balance'])->distinct()->pluck('invoice_type')->all())));
            if ($report === 'due-bills') {
                $types = array_merge($types, ['Opening Due', 'Opening Balance']);
            }
            $select('invoice_type', 'Invoice type', array_combine($types, $types));
            $states = ['Paid', 'Partially Paid', 'Unpaid', 'Hold', 'Partially Paid / Hold'];
            $select('status', 'Payment status', array_combine($states, $states));
        }
        if (in_array($report, ['index', 'spare-parts', 'purchases', 'expenses', 'drawings', 'collections', 'main-account', 'spare-parts-account', 'bank-account', 'washing-account'])) {
            $methods = ['Cash', 'Card', 'Bank Transfer', 'Cheque', 'QR', 'Credit/Due', 'Mixed', 'Online', 'Other'];
            if ($report === 'collections') {
                $methods = array_merge($methods, ['Salary Deduction', 'Manual Clearance']);
            }
            $select('payment_method', 'Payment method', array_combine($methods, $methods));
        }
        if (in_array($report, ['job-cards', 'technicians', 'attendance', 'salary', 'epf', 'etf'])) {
            $select('technician', 'Technician', Technician::orderBy('name')->pluck('name', 'id')->all(), true);
        }
        $statuses = match ($report) {
            'job-cards' => ['Received', 'Assigned', 'Diagnosis', 'In Progress', 'Waiting for Parts', 'Washing / Final Check', 'On Hold', 'Repair Ready', 'Ready for Delivery', 'Pending Payment', 'Completed', 'Billed', 'Delivered', 'Cancelled'],
            'attendance' => ['Present', 'Late', 'Absent', 'Half Day', 'Morning Half Day', 'Evening Half Day', 'Early Leave', 'Auto Closed', 'Worked on Leave Day', 'Holiday', 'Leave', 'Paid Leave', 'No Pay Leave'],
            'salary' => ['Draft', 'Finalized', 'Partially Paid', 'Paid'],
            'collections' => ['Completed', 'Hold'],
            'service-reminders' => ['Pending', 'Sent', 'Completed'],
            'sms' => ['sent', 'failed', 'pending'],
            'daily-closing' => ['closed', 'reopened'],
            default => [],
        };
        if ($statuses) {
            $select('status', 'Status', array_combine($statuses, $statuses));
        }
        if (in_array($report, ['purchases', 'supplier-dues'])) {
            $select('supplier_id', 'Supplier', Supplier::orderBy('name')->pluck('name', 'id')->all(), true);
            $select('payment_status', 'Payment status', ['Paid' => 'Paid', 'Partial' => 'Partial', 'Due' => 'Due', 'Hold' => 'Hold', 'Partial / Hold' => 'Partial / Hold']);
        }
        if (in_array($report, ['stock', 'purchases', 'supplier-dues', 'spare-parts'])) {
            $select('store_id', 'Store', Store::orderBy('name')->pluck('name', 'id')->all(), true);
        }
        if ($report === 'stock') {
            $select('category_id', 'Category', ProductCategory::orderBy('name')->pluck('name', 'id')->all(), true);
        }
        if ($report === 'expenses') {
            $select('category', 'Category', Expense::whereNotNull('category')->distinct()->pluck('category', 'category')->all());
        }
        if (in_array($report, ['expenses', 'drawings'])) {
            $select('account_id', 'Account', Account::orderBy('name')->pluck('name', 'id')->all(), true);
        }
        if ($report === 'technicians') {
            $select('active', 'Staff status', ['1' => 'Active', '0' => 'Inactive']);
        }
        if ($report === 'drawings') {
            $select('drawing_type', 'Drawing type', Drawing::whereHas('account')->distinct()->pluck('drawing_type', 'drawing_type')->all());
        }
        if ($report === 'service-reminders') {
            $select('sms_status', 'SMS status', ['Not Sent' => 'Not sent', 'sent' => 'Sent', 'failed' => 'Failed']);
        }
        if ($report === 'sms') {
            $select('module', 'Module', SmsLog::whereNotNull('module')->distinct()->pluck('module', 'module')->all());
        }
        if ($report === 'cash-book') {
            $cash = CashBook::query();
            $types = (clone $cash)->distinct()->pluck('transaction_type');
            $select('type', 'Entry type', $types->mapWithKeys(fn ($type) => [$type => ucwords(str_replace('_', ' ', $type))])->all());
            $select('direction', 'Cash direction', ['in' => 'Cash in', 'out' => 'Cash out']);
            $select('created_by', 'Added by', User::withTrashed()->whereIn('id', (clone $cash)->select('created_by'))->orderBy('name')->pluck('name', 'id')->all(), true);
        }
        if (in_array($report, ['main-account', 'spare-parts-account', 'bank-account', 'washing-account'])) {
            $types = AccountTransaction::where('account_id', Account::systemAccount(match ($report) {
                'main-account' => 'main', 'spare-parts-account' => 'spare_parts', 'washing-account' => 'washing', default => 'bank'
            })->id)->distinct()->pluck('transaction_type');
            $select('type', 'Transaction type', $types->mapWithKeys(fn ($type) => [$type => ucwords(str_replace('_', ' ', $type))])->all());
        }

        return $fields;
    }
}
