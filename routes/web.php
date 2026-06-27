<?php

use App\Http\Controllers\BikeIntakeController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FinanceController;
use App\Http\Controllers\JobCardController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\PublicInvoiceController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\TechnicianController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/login')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::get('bike-intakes/lookup/{number}', [BikeIntakeController::class, 'lookup'])->name('intakes.lookup');
    Route::resource('bike-intakes', BikeIntakeController::class)->parameters(['bike-intakes' => 'intake'])->only(['index', 'create', 'store', 'show'])->names('intakes');
    Route::post('job-cards/{job}/parts', [JobCardController::class, 'addPart'])->name('jobs.parts.store');
    Route::post('job-cards/{job}/invoice', [JobCardController::class, 'invoice'])->name('jobs.invoice');
    Route::resource('job-cards', JobCardController::class)->parameters(['job-cards' => 'job'])->only(['index', 'create', 'store', 'show', 'update'])->names('jobs');
    Route::post('products/{product}/stock', [ProductController::class, 'stock'])->name('products.stock');
    Route::resource('products', ProductController::class)->only(['index', 'store']);
    Route::post('technicians/{technician}/attendance', [TechnicianController::class, 'attendance'])->name('technicians.attendance');
    Route::resource('technicians', TechnicianController::class)->only(['index', 'store', 'show']);
    Route::get('accounts', [FinanceController::class, 'index'])->name('finance.index');
    Route::post('expenses', [FinanceController::class, 'expense'])->name('expenses.store');
    Route::post('drawings', [FinanceController::class, 'drawing'])->name('drawings.store');
    Route::post('account-transfers', [FinanceController::class, 'transfer'])->name('transfers.store');
    Route::get('reports', [ReportController::class, 'index'])->name('reports.index');
    Route::get('system-settings', [SettingController::class, 'index'])->name('system-settings.index');
    Route::put('system-settings', [SettingController::class, 'update'])->name('system-settings.update');
});

Route::get('invoice/{token}', PublicInvoiceController::class)->name('invoices.public');

require __DIR__.'/settings.php';
