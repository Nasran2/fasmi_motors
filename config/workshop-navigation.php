<?php

use Illuminate\Support\Str;

$items = fn (array $labels): array => array_map(
    fn (string $label): array => ['title' => $label, 'slug' => Str::slug($label)],
    $labels,
);

return [
    'groups' => [
        ['slug' => 'dashboard', 'title' => 'Dashboard', 'icon' => 'dashboard', 'children' => $items(['Overview', 'Today Summary', 'Workshop Performance'])],
        ['slug' => 'bike-intake', 'title' => 'Bike Intake', 'icon' => 'bike', 'children' => $items(['New Bike Intake', 'Intake List', 'Pending Intakes', 'Service Jobs', 'Technician Bikes', 'Due Bike Records'])],
        ['slug' => 'job-cards', 'title' => 'Job Cards', 'icon' => 'clipboard', 'children' => $items(['Create Job Card', 'All Job Cards', 'Assigned Jobs', 'In Progress Jobs', 'Waiting for Parts', 'Completed Jobs', 'Delivered Jobs', 'Cancelled Jobs'])],
        ['slug' => 'customers-bikes', 'title' => 'Customers & Bikes', 'icon' => 'customers', 'children' => $items(['Customer List', 'Add Customer', 'Bike List', 'Add Bike', 'Customer Due', 'Service History', 'Bike Ownership Change'])],
        ['slug' => 'products-stock', 'title' => 'Products / Stock', 'icon' => 'stock', 'children' => $items(['Product List', 'Add Product', 'Suppliers', 'Import Products', 'Barcode Print', 'Categories', 'Brands', 'Units', 'Stores', 'Store Stock', 'Stock Transfer', 'Stock Adjustment', 'Low Stock', 'Damage / Write-off'])],
        ['slug' => 'purchases', 'title' => 'Purchases', 'icon' => 'purchase', 'children' => $items(['Purchase List', 'Add Purchase', 'Purchase Returns', 'Supplier List', 'Add Supplier', 'Supplier Payments', 'Purchase Due'])],
        ['slug' => 'sales-billing', 'title' => 'Sales / Billing', 'icon' => 'billing', 'children' => $items(['Job Card Billing', 'Parts Sales', 'Invoice List', 'Payments', 'Due Bills', 'Customer Payments', 'Public Bill Links'])],
        ['slug' => 'technicians', 'title' => 'Technicians', 'icon' => 'technician', 'children' => $items(['Technician List', 'Add Technician', 'Technician Attendance', 'Salary Payments', 'Advance Payments', 'Commission', 'Own Bike Deductions', 'EPF / ETF Records', 'Technician History'])],
        ['slug' => 'accounts', 'title' => 'Accounts', 'icon' => 'accounts', 'children' => $items(['Main Account Ledger', 'Spare Parts Ledger', 'Bank Account Ledger', 'Cash Book', 'Account Transfers', 'Daily Closing', 'Cheque Payments', 'Card / Bank Payments', 'Opening Balances'])],
        ['slug' => 'expenses-drawings', 'title' => 'Expenses & Drawings', 'icon' => 'expense', 'children' => $items(['Expense List', 'Add Expense', 'Expense Categories', 'Drawing List', 'Add Drawing', 'Drawing Categories'])],
        ['slug' => 'service-reminders', 'title' => 'Service Reminders', 'icon' => 'reminder', 'children' => $items(['Upcoming Reminders', 'Sent Reminders', 'Overdue Services', 'Reminder SMS Logs', 'Reminder Settings'])],
        ['slug' => 'sms-center', 'title' => 'SMS Center', 'icon' => 'sms', 'children' => $items(['SMS Dashboard', 'Send SMS', 'SMS Templates', 'SMS Logs', 'Failed SMS', 'SMS Provider Settings'])],
        ['slug' => 'reports', 'title' => 'Reports', 'icon' => 'reports', 'children' => $items(['Sales Report', 'Job Card Report', 'Spare Parts Sales Report', 'Profit & Loss', 'Stock Report', 'Purchase Report', 'Expense Report', 'Drawing Report', 'Due Bills Report', 'Customer Due Report', 'Technician Report', 'Attendance Report', 'Salary Report', 'EPF Report', 'ETF Report', 'Main Account Report', 'Spare Parts Account Report', 'Bank Account Report', 'Daily Closing Report', 'Service Reminder Report', 'SMS Report'])],
        ['slug' => 'user-management', 'title' => 'User Management', 'icon' => 'users', 'children' => $items(['Users', 'Add User', 'Roles', 'Permissions', 'Activity Logs', 'Login History'])],
        ['slug' => 'settings', 'title' => 'Settings', 'icon' => 'settings', 'children' => $items(['General Settings', 'Business Profile', 'Branch / Store Settings', 'Invoice Settings', 'Job Card Settings', 'Account Settings', 'Stock Settings', 'Technician Settings', 'Salary Settings', 'EPF / ETF Settings', 'SMS Settings', 'Service Reminder Settings', 'Payment Method Settings', 'Tax / Discount Settings', 'Backup Settings', 'System Security', 'Audit Log Settings'])],
    ],

    'direct' => [
        'dashboard.overview' => ['route' => 'dashboard'],
        'bike-intake.new-bike-intake' => ['route' => 'intakes.create'],
        'bike-intake.intake-list' => ['route' => 'intakes.index', 'active' => ['intakes.index', 'intakes.show']],
        'job-cards.create-job-card' => ['route' => 'jobs.create'],
        'job-cards.all-job-cards' => ['route' => 'jobs.index', 'active' => ['jobs.index', 'jobs.show']],
        'customers-bikes.customer-list' => ['route' => 'customers.index', 'active' => ['customers.index', 'customers.show', 'customers.update', 'customers.deactivate']],
        'customers-bikes.add-customer' => ['route' => 'customers.create'],
        'customers-bikes.bike-list' => ['route' => 'customers.bikes.index', 'active' => ['customers.bikes.index', 'customers.bikes.show']],
        'customers-bikes.add-bike' => ['route' => 'customers.bikes.create'],
        'customers-bikes.customer-due' => ['route' => 'customers.dues', 'active' => ['customers.dues', 'customers.dues.payment']],
        'customers-bikes.service-history' => ['route' => 'customers.service-history'],
        'customers-bikes.bike-ownership-change' => ['route' => 'customers.ownership', 'active' => ['customers.ownership', 'customers.ownership.store']],
        'products-stock.product-list' => ['route' => 'products.index'],
        'products-stock.add-product' => ['route' => 'products.create'],
        'products-stock.suppliers' => ['route' => 'products.suppliers', 'active' => ['products.suppliers', 'products.suppliers.store']],
        'products-stock.categories' => ['route' => 'products.references', 'parameters' => ['tab' => 'categories'], 'query' => ['tab' => 'categories']],
        'products-stock.brands' => ['route' => 'products.references', 'parameters' => ['tab' => 'brands'], 'query' => ['tab' => 'brands']],
        'products-stock.units' => ['route' => 'products.references', 'parameters' => ['tab' => 'units'], 'query' => ['tab' => 'units']],
        'products-stock.stores' => ['route' => 'products.stores', 'active' => ['products.stores', 'products.stores.store']],
        'products-stock.store-stock' => ['route' => 'products.store-stock'],
        'products-stock.stock-transfer' => ['route' => 'products.transfers', 'active' => ['products.transfers', 'products.transfers.store']],
        'products-stock.stock-adjustment' => ['route' => 'products.adjustments', 'active' => ['products.adjustments', 'products.adjustments.store']],
        'products-stock.low-stock' => ['route' => 'products.low-stock'],
        'products-stock.damage-write-off' => ['route' => 'products.damages', 'active' => ['products.damages', 'products.damages.store']],
        'purchases.supplier-list' => ['route' => 'products.suppliers', 'parameters' => ['view' => 'list'], 'query' => ['view' => 'list']],
        'purchases.add-supplier' => ['route' => 'products.suppliers', 'parameters' => ['view' => 'add'], 'query' => ['view' => 'add']],
        'technicians.technician-list' => ['route' => 'technicians.index', 'active' => ['technicians.index', 'technicians.show']],
        'accounts.main-account-ledger' => ['route' => 'finance.index'],
        'reports.sales-report' => ['route' => 'reports.index'],
        'settings.general-settings' => ['route' => 'system-settings.index'],
    ],
];
