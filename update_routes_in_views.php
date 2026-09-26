<?php
function replace_routes($file) {
    if (!file_exists($file)) return;
    $content = file_get_contents($file);
    $content = str_replace("route('customers.export')", "route('customers.excel.export')", $content);
    $content = str_replace("route('customers.import')", "route('customers.excel.import')", $content);
    $content = str_replace("route('products.export')", "route('products.excel.export')", $content);
    $content = str_replace("route('products.import')", "route('products.excel.import')", $content);
    file_put_contents($file, $content);
}

replace_routes('resources/views/customers/index.blade.php');
replace_routes('resources/views/products/index.blade.php');
replace_routes('resources/views/data-management/index.blade.php');
echo "Updated routes in views.";
