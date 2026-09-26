<?php
$file = 'routes/web.php';
$content = file_get_contents($file);

$customerRoutes = <<<'ROUTES'
    Route::get('customers-export', [\App\Http\Controllers\CustomerBikeController::class, 'export'])->name('customers.export');
    Route::post('customers-import', [\App\Http\Controllers\CustomerBikeController::class, 'import'])->name('customers.import');
    Route::resource('customers', CustomerBikeController::class);
ROUTES;

$productRoutes = <<<'ROUTES'
    Route::get('products-export', [\App\Http\Controllers\ProductController::class, 'export'])->name('products.export');
    Route::post('products-import', [\App\Http\Controllers\ProductController::class, 'import'])->name('products.import');
    Route::resource('products', ProductController::class);
ROUTES;

$content = str_replace("Route::resource('customers', CustomerBikeController::class);", $customerRoutes, $content);
$content = str_replace("Route::resource('products', ProductController::class);", $productRoutes, $content);

file_put_contents($file, $content);
echo "Routes added!";
