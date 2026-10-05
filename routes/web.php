<?php

use App\Http\Controllers\Accounts\AccountExportController;
use App\Http\Controllers\Accounts\AccountTransferController;
use App\Http\Controllers\Accounts\BankAccountController;
use App\Http\Controllers\Accounts\CashBookController;
use App\Http\Controllers\Accounts\ChequeController;
use App\Http\Controllers\Accounts\DailyClosingController;
use App\Http\Controllers\Accounts\MainAccountController;
use App\Http\Controllers\Accounts\OpeningBalanceController;
use App\Http\Controllers\Accounts\SparePartsAccountController;
use App\Http\Controllers\BikeIntakeController;
use App\Http\Controllers\CustomerBikeController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ExpenseDrawingController;
use App\Http\Controllers\FinanceController;
use App\Http\Controllers\GlobalSearchController;
use App\Http\Controllers\JobCardController;
use App\Http\Controllers\LeaveHolidaySettingController;
use App\Http\Controllers\LiveBoardController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\PublicInvoiceController;
use App\Http\Controllers\PurchaseController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SalesBillingController;
use App\Http\Controllers\AccidentQuotationController;
use App\Http\Controllers\ServiceReminderController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\SmsCenterController;
use App\Http\Controllers\TechnicianController;
use App\Http\Controllers\UserManagementController;
use App\Http\Controllers\WorkspacePageController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/login')->name('home');

Route::middleware(['auth', 'verified', 'active.user', 'route.permission'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::get('search', GlobalSearchController::class)->name('search');
    Route::post('notifications/clear', [\App\Http\Controllers\WorkshopNotificationController::class, 'clear'])->name('notifications.clear');
    Route::get('live-bike-status', [LiveBoardController::class, 'index'])->name('live-board.index');
    Route::get('workshop/live-board', [LiveBoardController::class, 'fullscreen'])->name('live-board.fullscreen');
    Route::get('workshop/live-board/customer', [LiveBoardController::class, 'customer'])->name('live-board.customer');
    Route::get('workshop/live-board/data', [LiveBoardController::class, 'data'])->name('live-board.data');
    Route::patch('workshop/live-board/jobs/{job}/status', [LiveBoardController::class, 'updateStatus'])->name('live-board.status');
    Route::get('customers', [CustomerBikeController::class, 'customers'])->name('customers.index');
    Route::get('customers/export', [CustomerBikeController::class, 'export'])->name('customers.excel.export');
    Route::post('customers/import', [CustomerBikeController::class, 'import'])->name('customers.excel.import');
    Route::get('customers/create', [CustomerBikeController::class, 'customerCreate'])->name('customers.create');
    Route::post('customers', [CustomerBikeController::class, 'customerStore'])->name('customers.store');
    Route::get('customers/{customer}', [CustomerBikeController::class, 'customerShow'])->name('customers.show');
    Route::put('customers/{customer}', [CustomerBikeController::class, 'customerUpdate'])->name('customers.update');
    Route::delete('customers/{customer}', [CustomerBikeController::class, 'customerDeactivate'])->name('customers.deactivate');
    Route::get('bikes', [CustomerBikeController::class, 'bikes'])->name('customers.bikes.index');
    Route::get('bikes/create', [CustomerBikeController::class, 'bikeCreate'])->name('customers.bikes.create');
    Route::post('bikes', [CustomerBikeController::class, 'bikeStore'])->name('customers.bikes.store');
    Route::get('bikes/{bike}', [CustomerBikeController::class, 'bikeShow'])->name('customers.bikes.show');
    Route::get('customer-dues', [CustomerBikeController::class, 'dues'])->name('customers.dues');
    Route::post('customer-dues/sms/preview', [\App\Http\Controllers\CustomerDueSmsController::class, 'preview'])->name('customers.dues.sms.preview');
    Route::post('customer-dues/sms/send', [\App\Http\Controllers\CustomerDueSmsController::class, 'send'])->name('customers.dues.sms.send');
    Route::post('customer-dues/{invoice}/payment', [CustomerBikeController::class, 'receivePayment'])->name('customers.dues.payment');
    Route::get('service-history', [CustomerBikeController::class, 'serviceHistory'])->name('customers.service-history');
    Route::get('bike-ownership', [CustomerBikeController::class, 'ownership'])->name('customers.ownership');
    Route::post('bike-ownership', [CustomerBikeController::class, 'ownershipStore'])->name('customers.ownership.store');
    Route::get('bike-intakes/lookup/{query}', [BikeIntakeController::class, 'lookup'])->name('intakes.lookup');
    Route::resource('bike-intakes', BikeIntakeController::class)->parameters(['bike-intakes' => 'intake'])->names('intakes');
    Route::post('job-cards/{job}/parts', [JobCardController::class, 'addPart'])->name('jobs.parts.store');
    Route::post('job-cards/{job}/parts/multiple', [JobCardController::class, 'addMultipleParts'])->name('jobs.parts.store_multiple');
    Route::post('job-cards/{job}/parts/{part}/update', [JobCardController::class, 'updatePart'])->name('jobs.parts.update');
    Route::delete('job-cards/{job}/parts/{part}', [JobCardController::class, 'deletePart'])->name('jobs.parts.destroy');
    Route::post('job-cards/{job}/invoice', [JobCardController::class, 'invoice'])->name('jobs.invoice');
    Route::post('job-cards/{job}/unlock', [JobCardController::class, 'unlock'])->name('jobs.unlock');
    Route::resource('job-cards', JobCardController::class)->parameters(['job-cards' => 'job'])->only(['index', 'create', 'store', 'show', 'update'])->names('jobs');
    Route::get('products/references', [ProductController::class, 'references'])->name('products.references');
    Route::post('products/references/{type}', [ProductController::class, 'referenceStore'])->name('products.references.store');
    Route::get('products/suppliers', [ProductController::class, 'suppliers'])->name('products.suppliers');
    Route::post('products/suppliers', [ProductController::class, 'supplierStore'])->name('products.suppliers.store');
    Route::get('products/store-stock', [ProductController::class, 'storeStock'])->name('products.store-stock');
    Route::get('products/stores', [ProductController::class, 'stores'])->name('products.stores');
    Route::post('products/stores', [ProductController::class, 'storeStore'])->name('products.stores.store');
    Route::get('products/transfers', [ProductController::class, 'transfers'])->name('products.transfers');
    Route::post('products/transfers', [ProductController::class, 'transferStore'])->name('products.transfers.store');
    Route::get('products/adjustments', [ProductController::class, 'adjustments'])->name('products.adjustments');
    Route::post('products/adjustments', [ProductController::class, 'adjustmentStore'])->name('products.adjustments.store');
    Route::get('products/low-stock', [ProductController::class, 'lowStock'])->name('products.low-stock');
    Route::get('products/damages', [ProductController::class, 'damages'])->name('products.damages');
    Route::post('products/damages', [ProductController::class, 'damageStore'])->name('products.damages.store');
    Route::get('products/import', [ProductController::class, 'importForm'])->name('products.import');
    Route::post('products/import', [ProductController::class, 'importStore'])->name('products.import.store');
    Route::post('products/{product}/stock', [ProductController::class, 'stock'])->name('products.stock');
    Route::get('products/pos-pins', [ProductController::class, 'posPins'])->name('products.pos-pins.index');
    Route::put('products/pos-pins/order', [ProductController::class, 'reorderPosPins'])->name('products.pos-pins.order');
    Route::post('products/{product}/pos-pin', [ProductController::class, 'togglePosPin'])->name('products.pos-pin.toggle');
    Route::get('products-export', [ProductController::class, 'export'])->name('products.excel.export');
    Route::post('products-import', [ProductController::class, 'import'])->name('products.excel.import');
    Route::resource('products', ProductController::class)->only(['index', 'create', 'store', 'show']);
    Route::get('purchases/returns', [PurchaseController::class, 'returns'])->name('purchases.returns');
    Route::post('purchases/returns', [PurchaseController::class, 'returnStore'])->name('purchases.returns.store');
    Route::post('purchases/returns/{return}/approve', [PurchaseController::class, 'approveReturn'])->name('purchases.returns.approve');
    Route::get('purchases/suppliers', [PurchaseController::class, 'suppliers'])->name('purchases.suppliers');
    Route::get('purchases/suppliers/create', [PurchaseController::class, 'supplierCreate'])->name('purchases.suppliers.create');
    Route::post('purchases/suppliers', [PurchaseController::class, 'supplierStore'])->name('purchases.suppliers.store');
    Route::get('purchases/suppliers/{supplier}', [PurchaseController::class, 'supplierShow'])->name('purchases.suppliers.show');
    Route::delete('purchases/suppliers/{supplier}', [PurchaseController::class, 'supplierDeactivate'])->name('purchases.suppliers.deactivate');
    Route::get('purchases/payments', [PurchaseController::class, 'payments'])->name('purchases.payments');
    Route::post('purchases/payments', [PurchaseController::class, 'paymentStore'])->name('purchases.payments.store');
    Route::get('purchases/dues', [PurchaseController::class, 'dues'])->name('purchases.dues');
    Route::delete('purchases/{purchase}', [PurchaseController::class, 'cancel'])->name('purchases.cancel');
    Route::resource('purchases', PurchaseController::class)->only(['index', 'create', 'store', 'show']);
    Route::get('sales/job-billing', [SalesBillingController::class, 'jobBilling'])->name('sales.job-billing');
    Route::post('sales/job-billing/{job}', [SalesBillingController::class, 'billJob'])->name('sales.job-billing.store');
    Route::get('sales/parts', [SalesBillingController::class, 'partsSales'])->name('sales.parts');
    Route::post('sales/parts', [SalesBillingController::class, 'partsStore'])->name('sales.parts.store');
    Route::get('sales/quotations', [SalesBillingController::class, 'quotations'])->name('sales.quotations');
    Route::get('sales/accident-quotations/create', [AccidentQuotationController::class, 'create'])->name('sales.accident-quotations.create');
    Route::post('sales/accident-quotations', [AccidentQuotationController::class, 'store'])->name('sales.accident-quotations.store');
    Route::get('sales/invoices', [SalesBillingController::class, 'invoices'])->name('sales.invoices');
    Route::get('sales/invoices/{invoice}', [SalesBillingController::class, 'invoiceShow'])->name('sales.invoices.show');
    Route::get('sales/invoices/{invoice}/pdf', [SalesBillingController::class, 'invoicePdf'])->name('sales.invoices.pdf');
    Route::delete('sales/invoices/{invoice}', [SalesBillingController::class, 'cancelInvoice'])->name('sales.invoices.cancel');
    Route::get('sales/payments', [SalesBillingController::class, 'payments'])->name('sales.payments');
    Route::post('sales/payments', [SalesBillingController::class, 'paymentStore'])->name('sales.payments.store');
    Route::delete('sales/payments/{payment}', [SalesBillingController::class, 'cancelPayment'])->name('sales.payments.cancel');
    Route::get('sales/dues', [SalesBillingController::class, 'dues'])->name('sales.dues');
    Route::get('sales/customer-payments', [SalesBillingController::class, 'customerPayments'])->name('sales.customer-payments');
    Route::get('sales/public-links', [SalesBillingController::class, 'publicLinks'])->name('sales.public-links');
    Route::post('sales/public-links/{invoice}/regenerate', [SalesBillingController::class, 'regenerateLink'])->name('sales.public-links.regenerate');
    Route::post('sales/public-links/{invoice}/toggle', [SalesBillingController::class, 'toggleLink'])->name('sales.public-links.toggle');
    Route::post('sales/public-links/{invoice}/sms', [SalesBillingController::class, 'sendBillSms'])->name('sales.public-links.sms');
    Route::get('technicians/create', [TechnicianController::class, 'create'])->name('technicians.create');
    Route::get('technicians/{technician}/edit', [TechnicianController::class, 'edit'])->name('technicians.edit');
    Route::get('technicians/attendance', [TechnicianController::class, 'attendanceIndex'])->name('technicians.attendance.index');
    Route::post('technicians/attendance', [TechnicianController::class, 'attendanceStore'])->name('technicians.attendance.store');
    Route::post('technicians/attendance/range', [TechnicianController::class, 'attendanceRangeStore'])->name('technicians.attendance.range.store');
    Route::post('technicians/attendance/import/preview', [TechnicianController::class, 'attendanceImportPreview'])->name('technicians.attendance.import.preview');
    Route::post('technicians/attendance/import/{batch}/confirm', [TechnicianController::class, 'attendanceImportConfirm'])->name('technicians.attendance.import.confirm');
    Route::get('technicians/salaries', [TechnicianController::class, 'salaries'])->name('technicians.salaries');
    Route::get('technicians/salaries/preview', [TechnicianController::class, 'salaryPreview'])->name('technicians.salaries.preview');
    Route::post('technicians/salaries', [TechnicianController::class, 'salaryStore'])->name('technicians.salaries.store');
    Route::post('technicians/salaries/{sheet}/finalize', [TechnicianController::class, 'salaryFinalize'])->name('technicians.salaries.finalize');
    Route::post('technicians/salaries/{sheet}/pay', [TechnicianController::class, 'salaryPay'])->name('technicians.salaries.pay');
    Route::delete('technicians/salaries/{sheet}', [TechnicianController::class, 'salaryDestroy'])->name('technicians.salaries.destroy');
    Route::post('technicians/salaries/{sheet}/reopen', [TechnicianController::class, 'salaryReopen'])->name('technicians.salaries.reopen');
    Route::get('technicians/salaries/{sheet}/slip', [TechnicianController::class, 'salarySlip'])->name('technicians.salaries.slip');
    Route::get('technicians/advances', [TechnicianController::class, 'advances'])->name('technicians.advances');
    Route::post('technicians/advances', [TechnicianController::class, 'advanceStore'])->name('technicians.advances.store');
    Route::get('technicians/commissions', [TechnicianController::class, 'commissions'])->name('technicians.commissions');
    Route::post('technicians/commissions', [TechnicianController::class, 'commissionStore'])->name('technicians.commissions.store');
    Route::get('technicians/bike-deductions', [TechnicianController::class, 'bikeDeductions'])->name('technicians.bike-deductions');
    Route::post('technicians/bike-deductions', [TechnicianController::class, 'bikeDeductionStore'])->name('technicians.bike-deductions.store');
    Route::get('technicians/contributions', [TechnicianController::class, 'contributions'])->name('technicians.contributions');
    Route::get('technicians/history', [TechnicianController::class, 'history'])->name('technicians.history');
    Route::post('technicians/{technician}/attendance', [TechnicianController::class, 'attendance'])->name('technicians.attendance');
    Route::resource('technicians', TechnicianController::class)->only(['index', 'store', 'show', 'update']);
    Route::get('accounts', [FinanceController::class, 'index'])->name('finance.index');
    Route::post('expenses', [FinanceController::class, 'expense'])->name('expenses.store');
    Route::post('drawings', [FinanceController::class, 'drawing'])->name('drawings.store');
    Route::get('expenses/list', [ExpenseDrawingController::class, 'expenses'])->name('expenses.index');
    Route::get('expenses/create', [ExpenseDrawingController::class, 'createExpense'])->name('expenses.create');
    Route::post('expenses/create', [ExpenseDrawingController::class, 'storeExpense'])->name('expenses.store.full');
    Route::get('expenses/categories', [ExpenseDrawingController::class, 'expenseCategories'])->name('expenses.categories');
    Route::get('drawings/list', [ExpenseDrawingController::class, 'drawings'])->name('drawings.index');
    Route::get('drawings/create', [ExpenseDrawingController::class, 'createDrawing'])->name('drawings.create');
    Route::post('drawings/create', [ExpenseDrawingController::class, 'storeDrawing'])->name('drawings.store.full');
    Route::get('drawings/categories', [ExpenseDrawingController::class, 'drawingCategories'])->name('drawings.categories');
    Route::post('finance-categories/{module}', [ExpenseDrawingController::class, 'storeCategory'])->name('finance-categories.store');
    Route::post('finance-module/{module}/{id}/cancel', [ExpenseDrawingController::class, 'cancel'])->name('finance-module.cancel');
    Route::get('finance-module/{module}/export', [ExpenseDrawingController::class, 'export'])->name('finance-module.export');
    Route::get('service-reminders/{page}', [ServiceReminderController::class, 'page'])->name('service-reminders.page');
    Route::post('service-reminders', [ServiceReminderController::class, 'store'])->name('service-reminders.store');
    Route::post('service-reminders/{reminder}/send', [ServiceReminderController::class, 'send'])->name('service-reminders.send');
    Route::post('service-reminders/{reminder}/complete', [ServiceReminderController::class, 'complete'])->name('service-reminders.complete');
    Route::post('service-reminders/{reminder}/reschedule', [ServiceReminderController::class, 'reschedule'])->name('service-reminders.reschedule');
    Route::post('service-reminder-settings', [ServiceReminderController::class, 'saveSettings'])->name('service-reminders.settings.save');
    Route::post('account-transfers', [FinanceController::class, 'transfer'])->name('transfers.store');
    // Reports Module
    Route::prefix('reports')->name('reports.')->group(function () {
        Route::get('/', [ReportController::class, 'index'])->name('index');
        Route::get('job-cards', [ReportController::class, 'jobCards'])->name('job-cards');
        Route::get('spare-parts', [ReportController::class, 'spareParts'])->name('spare-parts');
        Route::get('profit-loss', [ReportController::class, 'profitLoss'])->name('profit-loss');
        Route::get('stock', [ReportController::class, 'stock'])->name('stock');
        Route::get('purchases', [ReportController::class, 'purchases'])->name('purchases');
        Route::get('expenses', [ReportController::class, 'expenses'])->name('expenses');
        Route::get('drawings', [ReportController::class, 'drawings'])->name('drawings');
        Route::get('due-bills', [ReportController::class, 'dueBills'])->name('due-bills');
        Route::get('customer-due', [ReportController::class, 'customerDue'])->name('customer-due');
        Route::get('technicians', [ReportController::class, 'technicians'])->name('technicians');
        Route::get('attendance', [ReportController::class, 'attendance'])->name('attendance');
        Route::get('salary', [ReportController::class, 'salary'])->name('salary');
        Route::get('epf', [ReportController::class, 'epf'])->name('epf');
        Route::get('etf', [ReportController::class, 'etf'])->name('etf');
        Route::get('main-account', [ReportController::class, 'mainAccount'])->name('main-account');
        Route::get('spare-parts-account', [ReportController::class, 'sparePartsAccount'])->name('spare-parts-account');
        Route::get('bank-account', [ReportController::class, 'bankAccount'])->name('bank-account');
        Route::get('daily-closing', [ReportController::class, 'dailyClosing'])->name('daily-closing');
        Route::get('service-reminders', [ReportController::class, 'serviceReminders'])->name('service-reminders');
        Route::get('sms', [ReportController::class, 'sms'])->name('sms');
    });
    Route::get('system-settings', [SettingController::class, 'index'])->name('system-settings.index');
    Route::put('system-settings', [SettingController::class, 'update'])->name('system-settings.update');

    Route::prefix('system-settings')->name('system-settings.')->group(function () {
        Route::get('general-settings', [SettingController::class, 'generalSettings'])->name('general-settings');
        Route::get('business-profile', [SettingController::class, 'businessProfile'])->name('business-profile');
        Route::get('branch-store-settings', [SettingController::class, 'branchStoreSettings'])->name('branch-store-settings');
        Route::get('invoice-settings', [SettingController::class, 'invoiceSettings'])->name('invoice-settings');
        Route::get('job-card-settings', [SettingController::class, 'jobCardSettings'])->name('job-card-settings');
        Route::get('account-settings', [SettingController::class, 'accountSettings'])->name('account-settings');
        Route::get('stock-settings', [SettingController::class, 'stockSettings'])->name('stock-settings');
        Route::get('technician-settings', [SettingController::class, 'technicianSettings'])->name('technician-settings');
        Route::get('salary-settings', [SettingController::class, 'salarySettings'])->name('salary-settings');
        Route::get('epf-etf-settings', [SettingController::class, 'epfEtfSettings'])->name('epf-etf-settings');
        Route::get('sms-settings', [SettingController::class, 'smsSettings'])->name('sms-settings');
        Route::get('service-reminder-settings', [SettingController::class, 'serviceReminderSettings'])->name('service-reminder-settings');
        Route::get('payment-method-settings', [SettingController::class, 'paymentMethodSettings'])->name('payment-method-settings');
        Route::get('tax-discount-settings', [SettingController::class, 'taxDiscountSettings'])->name('tax-discount-settings');
        Route::get('backup-settings', [SettingController::class, 'backupSettings'])->name('backup-settings');
        Route::get('system-security', [SettingController::class, 'systemSecurity'])->name('system-security');
        Route::get('audit-log-settings', [SettingController::class, 'auditLogSettings'])->name('audit-log-settings');

        // Leave & Holiday Settings
        Route::get('leave-holiday-settings', [LeaveHolidaySettingController::class, 'index'])->name('leave-holiday-settings');
        Route::post('leave-holiday-settings/weekly', [LeaveHolidaySettingController::class, 'storeWeeklyLeaves'])->name('leave-holiday-settings.weekly');
        Route::post('leave-holiday-settings/import-sl', [LeaveHolidaySettingController::class, 'importSriLankaHolidays'])->name('leave-holiday-settings.import-sl');
        Route::post('leave-holiday-settings/holidays', [LeaveHolidaySettingController::class, 'storeHoliday'])->name('leave-holiday-settings.holidays');
        Route::delete('leave-holiday-settings/holidays/{holiday}', [LeaveHolidaySettingController::class, 'destroyHoliday'])->name('leave-holiday-settings.holidays.destroy');
        Route::patch('leave-holiday-settings/holidays/{holiday}/toggle', [LeaveHolidaySettingController::class, 'toggleHoliday'])->name('leave-holiday-settings.holidays.toggle');

        // Actions
        Route::post('save', [SettingController::class, 'save'])->name('save');
        Route::post('test-sms', [SettingController::class, 'testSms'])->name('test-sms');
        Route::post('create-backup', [SettingController::class, 'createBackup'])->name('create-backup');
        Route::post('restore-backup', [SettingController::class, 'restoreBackup'])->name('restore-backup');
        Route::post('delete-backup', [SettingController::class, 'deleteBackup'])->name('delete-backup');
        Route::post('verify-developer-pin', [SettingController::class, 'verifyDeveloperPin'])->name('verify-developer-pin');

        // Store CRUD
        Route::post('stores', [SettingController::class, 'storeStore'])->name('stores.store');
        Route::put('stores/{store}', [SettingController::class, 'updateStore'])->name('stores.update');
    });

    // User Management Module
    Route::prefix('user-management')->name('user-management.')->group(function () {
        // Users
        Route::get('users', [UserManagementController::class, 'index'])->name('users.index');
        Route::get('users/create', [UserManagementController::class, 'create'])->name('users.create');
        Route::post('users', [UserManagementController::class, 'store'])->name('users.store');
        Route::get('users/{user}/edit', [UserManagementController::class, 'edit'])->name('users.edit');
        Route::put('users/{user}', [UserManagementController::class, 'update'])->name('users.update');
        Route::post('users/{user}/reset-password', [UserManagementController::class, 'resetPassword'])->name('users.reset-password');
        Route::post('users/{user}/toggle-status', [UserManagementController::class, 'toggleStatus'])->name('users.toggle-status');
        Route::delete('users/{user}', [UserManagementController::class, 'destroy'])->name('users.destroy');

        // Roles
        Route::get('roles', [UserManagementController::class, 'roles'])->name('roles.index');
        Route::post('roles', [UserManagementController::class, 'storeRole'])->name('roles.store');
        Route::put('roles/{role}', [UserManagementController::class, 'updateRole'])->name('roles.update');
        Route::delete('roles/{role}', [UserManagementController::class, 'destroyRole'])->name('roles.destroy');

        // Permissions
        Route::get('permissions', [UserManagementController::class, 'permissions'])->name('permissions.index');
        Route::post('permissions', [UserManagementController::class, 'updatePermissions'])->name('permissions.update');

        // Logs & History
        Route::get('activity-logs', [UserManagementController::class, 'activityLogs'])->name('activity-logs.index');
        Route::get('login-history', [UserManagementController::class, 'loginHistory'])->name('login-history.index');
    });

    // Accounts Module
    Route::prefix('accounts')->name('accounts.')->group(function () {
        Route::get('main-ledger', [MainAccountController::class, 'index'])->name('main-ledger.index');
        Route::post('main-ledger/{transaction}/reverse', [MainAccountController::class, 'reverse'])->name('main-ledger.reverse');

        Route::get('spare-parts-ledger', [SparePartsAccountController::class, 'index'])->name('spare-parts-ledger.index');
        Route::post('spare-parts-ledger/{transaction}/reverse', [SparePartsAccountController::class, 'reverse'])->name('spare-parts-ledger.reverse');

        Route::get('bank-ledger', [BankAccountController::class, 'index'])->name('bank-ledger.index');
        Route::post('bank-ledger/{transaction}/reverse', [BankAccountController::class, 'reverse'])->name('bank-ledger.reverse');

        Route::get('cash-book', [CashBookController::class, 'index'])->name('cash-book.index');
        Route::post('cash-book/{transaction}/reverse', [CashBookController::class, 'reverse'])->name('cash-book.reverse');

        Route::get('transfers', [AccountTransferController::class, 'index'])->name('transfers.index');
        Route::post('transfers', [AccountTransferController::class, 'store'])->name('transfers.store');
        Route::post('transfers/{transfer}/reverse', [AccountTransferController::class, 'reverse'])->name('transfers.reverse');

        Route::get('daily-closing', [DailyClosingController::class, 'index'])->name('daily-closing.index');
        Route::post('daily-closing', [DailyClosingController::class, 'store'])->name('daily-closing.store');
        Route::post('daily-closing/adjustment', [DailyClosingController::class, 'adjustment'])->name('daily-closing.adjustment');
        Route::get('daily-closing/export', [DailyClosingController::class, 'export'])->name('daily-closing.export');
        Route::post('daily-closing/{closing}/reopen', [DailyClosingController::class, 'reopen'])->name('daily-closing.reopen');

        Route::get('cheques', [ChequeController::class, 'index'])->name('cheques.index');
        Route::post('cheques', [ChequeController::class, 'store'])->name('cheques.store');
        Route::patch('cheques/{cheque}/status', [ChequeController::class, 'updateStatus'])->name('cheques.status');

        // Card payments is essentially a filtered view of bank ledger. Using BankAccountController for now.
        Route::get('card-payments', [BankAccountController::class, 'index'])->name('card-payments.index');

        Route::get('opening-balances', [OpeningBalanceController::class, 'index'])->name('opening-balances.index');
        Route::post('opening-balances', [OpeningBalanceController::class, 'store'])->name('opening-balances.store');

        Route::get('export/{type}', [AccountExportController::class, 'export'])->name('export');
    });

    Route::get('workspace/{section}/{page}', WorkspacePageController::class)
        ->where(['section' => '[a-z0-9-]+', 'page' => '[a-z0-9-]+'])
        ->name('workspace.page');

    // ── SMS Center ────────────────────────────────────────────────
    Route::prefix('sms')->name('sms.')->group(function () {
        Route::get('dashboard', [SmsCenterController::class, 'dashboard'])->name('dashboard');
        Route::get('send', [SmsCenterController::class, 'send'])->name('send');
        Route::post('send', [SmsCenterController::class, 'sendPost'])->name('send.post');
        Route::get('templates', [SmsCenterController::class, 'templates'])->name('templates');
        Route::get('templates/create', [SmsCenterController::class, 'templateCreate'])->name('templates.create');
        Route::post('templates', [SmsCenterController::class, 'templateStore'])->name('templates.store');
        Route::get('templates/{template}/edit', [SmsCenterController::class, 'templateEdit'])->name('templates.edit');
        Route::put('templates/{template}', [SmsCenterController::class, 'templateUpdate'])->name('templates.update');
        Route::delete('templates/{template}', [SmsCenterController::class, 'templateDestroy'])->name('templates.destroy');
        Route::post('templates/{template}/toggle', [SmsCenterController::class, 'templateToggle'])->name('templates.toggle');
        Route::get('logs', [SmsCenterController::class, 'logs'])->name('logs');
        Route::get('failed', [SmsCenterController::class, 'failed'])->name('failed');
        Route::post('resend/{smsLog}', [SmsCenterController::class, 'resend'])->name('resend');
        Route::get('settings', [SmsCenterController::class, 'settings'])->name('settings');
        Route::post('settings', [SmsCenterController::class, 'settingsUpdate'])->name('settings.update');
        Route::post('settings/test', [SmsCenterController::class, 'testSms'])->name('settings.test');
        Route::get('search-recipients', [SmsCenterController::class, 'searchRecipients'])->name('search-recipients');
    });

    Route::post('shop-switch/{mode}', function ($mode) {
        if (in_array($mode, ['workshop', 'helmet'])) {
            session(['shop_mode' => $mode]);
        }

        return redirect()->back();
    })->name('shop.switch');

    Route::get('data-management', [\App\Http\Controllers\DataManagementController::class, 'index'])->name('data-management.index');
});

Route::get('invoice/{token}', PublicInvoiceController::class)->name('invoices.public');
Route::get('invoice/{token}/pdf', [PublicInvoiceController::class, 'pdf'])->name('invoices.public.pdf');

require __DIR__.'/settings.php';
