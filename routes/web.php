<?php

use App\Http\Controllers\BikeIntakeController;
use App\Http\Controllers\CustomerBikeController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FinanceController;
use App\Http\Controllers\GlobalSearchController;
use App\Http\Controllers\JobCardController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\PublicInvoiceController;
use App\Http\Controllers\PurchaseController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SalesBillingController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\TechnicianController;
use App\Http\Controllers\WorkspacePageController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/login')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::get('search', GlobalSearchController::class)->name('search');
    Route::get('customers', [CustomerBikeController::class, 'customers'])->name('customers.index');
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
    Route::post('customer-dues/{invoice}/payment', [CustomerBikeController::class, 'receivePayment'])->name('customers.dues.payment');
    Route::get('service-history', [CustomerBikeController::class, 'serviceHistory'])->name('customers.service-history');
    Route::get('bike-ownership', [CustomerBikeController::class, 'ownership'])->name('customers.ownership');
    Route::post('bike-ownership', [CustomerBikeController::class, 'ownershipStore'])->name('customers.ownership.store');
    Route::get('bike-intakes/lookup/{number}', [BikeIntakeController::class, 'lookup'])->name('intakes.lookup');
    Route::resource('bike-intakes', BikeIntakeController::class)->parameters(['bike-intakes' => 'intake'])->only(['index', 'create', 'store', 'show'])->names('intakes');
    Route::post('job-cards/{job}/parts', [JobCardController::class, 'addPart'])->name('jobs.parts.store');
    Route::post('job-cards/{job}/invoice', [JobCardController::class, 'invoice'])->name('jobs.invoice');
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
    Route::post('products/{product}/stock', [ProductController::class, 'stock'])->name('products.stock');
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
    Route::get('sales/invoices', [SalesBillingController::class, 'invoices'])->name('sales.invoices');
    Route::get('sales/invoices/{invoice}', [SalesBillingController::class, 'invoiceShow'])->name('sales.invoices.show');
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
    Route::get('technicians/attendance', [TechnicianController::class, 'attendanceIndex'])->name('technicians.attendance.index');
    Route::post('technicians/attendance', [TechnicianController::class, 'attendanceStore'])->name('technicians.attendance.store');
    Route::get('technicians/salaries', [TechnicianController::class, 'salaries'])->name('technicians.salaries');
    Route::post('technicians/salaries', [TechnicianController::class, 'salaryStore'])->name('technicians.salaries.store');
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
    Route::post('account-transfers', [FinanceController::class, 'transfer'])->name('transfers.store');
    Route::get('reports', [ReportController::class, 'index'])->name('reports.index');
    Route::get('system-settings', [SettingController::class, 'index'])->name('system-settings.index');
    Route::put('system-settings', [SettingController::class, 'update'])->name('system-settings.update');
    
    // Accounts Module
    Route::prefix('accounts')->name('accounts.')->group(function () {
        Route::get('main-ledger', [\App\Http\Controllers\Accounts\MainAccountController::class, 'index'])->name('main-ledger.index');
        Route::post('main-ledger/{transaction}/reverse', [\App\Http\Controllers\Accounts\MainAccountController::class, 'reverse'])->name('main-ledger.reverse');
        
        Route::get('spare-parts-ledger', [\App\Http\Controllers\Accounts\SparePartsAccountController::class, 'index'])->name('spare-parts-ledger.index');
        Route::post('spare-parts-ledger/{transaction}/reverse', [\App\Http\Controllers\Accounts\SparePartsAccountController::class, 'reverse'])->name('spare-parts-ledger.reverse');

        Route::get('bank-ledger', [\App\Http\Controllers\Accounts\BankAccountController::class, 'index'])->name('bank-ledger.index');
        Route::post('bank-ledger/{transaction}/reverse', [\App\Http\Controllers\Accounts\BankAccountController::class, 'reverse'])->name('bank-ledger.reverse');

        Route::get('cash-book', [\App\Http\Controllers\Accounts\CashBookController::class, 'index'])->name('cash-book.index');
        Route::post('cash-book/{transaction}/reverse', [\App\Http\Controllers\Accounts\CashBookController::class, 'reverse'])->name('cash-book.reverse');

        Route::get('transfers', [\App\Http\Controllers\Accounts\AccountTransferController::class, 'index'])->name('transfers.index');
        Route::post('transfers', [\App\Http\Controllers\Accounts\AccountTransferController::class, 'store'])->name('transfers.store');

        Route::get('daily-closing', [\App\Http\Controllers\Accounts\DailyClosingController::class, 'index'])->name('daily-closing.index');
        Route::post('daily-closing', [\App\Http\Controllers\Accounts\DailyClosingController::class, 'store'])->name('daily-closing.store');

        Route::get('cheques', [\App\Http\Controllers\Accounts\ChequeController::class, 'index'])->name('cheques.index');
        Route::patch('cheques/{cheque}/status', [\App\Http\Controllers\Accounts\ChequeController::class, 'updateStatus'])->name('cheques.status');

        // Card payments is essentially a filtered view of bank ledger. Using BankAccountController for now.
        Route::get('card-payments', [\App\Http\Controllers\Accounts\BankAccountController::class, 'index'])->name('card-payments.index');

        Route::get('opening-balances', [\App\Http\Controllers\Accounts\OpeningBalanceController::class, 'index'])->name('opening-balances.index');
        Route::post('opening-balances', [\App\Http\Controllers\Accounts\OpeningBalanceController::class, 'store'])->name('opening-balances.store');
        
        Route::get('export/{type}', [\App\Http\Controllers\Accounts\AccountExportController::class, 'export'])->name('export');
    });

    Route::get('workspace/{section}/{page}', WorkspacePageController::class)
        ->where(['section' => '[a-z0-9-]+', 'page' => '[a-z0-9-]+'])
        ->name('workspace.page');
});

Route::get('invoice/{token}', PublicInvoiceController::class)->name('invoices.public');

require __DIR__.'/settings.php';
