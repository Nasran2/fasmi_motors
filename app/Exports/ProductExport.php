<?php
namespace App\Exports;

use App\Models\Product;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class ProductExport implements FromCollection, WithHeadings, WithMapping
{
    public function collection()
    {
        return Product::all();
    }

    public function headings(): array
    {
        return [
            'Code',
            'Barcode',
            'Name',
            'Category ID',
            'Brand',
            'Model Compatibility',
            'Cost Price',
            'Selling Price',
            'Wholesale Price',
            'Rack Number',
            'Minimum Stock',
            'Reorder Level',
            'Unit',
            'Shop Type'
        ];
    }

    public function map($product): array
    {
        return [
            $product->code,
            $product->barcode,
            $product->name,
            $product->category_id,
            $product->brand,
            $product->model_compatibility,
            $product->cost_price,
            $product->selling_price,
            $product->wholesale_price,
            $product->rack_number,
            $product->minimum_stock,
            $product->reorder_level,
            $product->unit,
            $product->shop_type
        ];
    }
}
