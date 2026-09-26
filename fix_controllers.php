<?php
$file = 'app/Http/Controllers/CustomerBikeController.php';
$content = file_get_contents($file);
$methods = <<<'METHODS'
    public function export()
    {
        return \Maatwebsite\Excel\Facades\Excel::download(new \App\Exports\CustomerExport, 'customers.xlsx');
    }

    public function import(\Illuminate\Http\Request $request)
    {
        $request->validate([
            'file' => 'required|mimes:xlsx,csv,xls',
        ]);

        \Maatwebsite\Excel\Facades\Excel::import(new \App\Imports\CustomerImport, $request->file('file'));

        return back()->with('success', 'Customers imported successfully!');
    }
}
METHODS;
$content = preg_replace('/\}\s*$/', $methods, $content);
file_put_contents($file, $content);

$file = 'app/Http/Controllers/ProductController.php';
$content = file_get_contents($file);
$methods = <<<'METHODS'
    public function export()
    {
        return \Maatwebsite\Excel\Facades\Excel::download(new \App\Exports\ProductExport, 'products.xlsx');
    }

    public function import(\Illuminate\Http\Request $request)
    {
        $request->validate([
            'file' => 'required|mimes:xlsx,csv,xls',
        ]);

        \Maatwebsite\Excel\Facades\Excel::import(new \App\Imports\ProductImport, $request->file('file'));

        return back()->with('success', 'Products imported successfully!');
    }
}
METHODS;
$content = preg_replace('/\}\s*$/', $methods, $content);
file_put_contents($file, $content);

echo "Controllers updated!";
