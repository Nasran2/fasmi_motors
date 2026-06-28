<?php

use App\Http\Controllers\BikeIntakeController;
use App\Http\Controllers\CustomerBikeController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FinanceController;
use App\Http\Controllers\GlobalSearchController;
use App\Http\Controllers\JobCardController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\PublicInvoiceController;
use App\Http\Controllers\ReportController;
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
    Route::resource('products', ProductController::class)->only(['index', 'create', 'store']);
    Route::post('technicians/{technician}/attendance', [TechnicianController::class, 'attendance'])->name('technicians.attendance');
    Route::resource('technicians', TechnicianController::class)->only(['index', 'store', 'show']);
    Route::get('accounts', [FinanceController::class, 'index'])->name('finance.index');
    Route::post('expenses', [FinanceController::class, 'expense'])->name('expenses.store');
    Route::post('drawings', [FinanceController::class, 'drawing'])->name('drawings.store');
    Route::post('account-transfers', [FinanceController::class, 'transfer'])->name('transfers.store');
    Route::get('reports', [ReportController::class, 'index'])->name('reports.index');
    Route::get('system-settings', [SettingController::class, 'index'])->name('system-settings.index');
    Route::put('system-settings', [SettingController::class, 'update'])->name('system-settings.update');
    Route::get('workspace/{section}/{page}', WorkspacePageController::class)
        ->where(['section' => '[a-z0-9-]+', 'page' => '[a-z0-9-]+'])
        ->name('workspace.page');
});

Route::get('invoice/{token}', PublicInvoiceController::class)->name('invoices.public');

require __DIR__.'/settings.php';
