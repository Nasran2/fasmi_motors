<?php
namespace App\Imports;

use App\Models\Product;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithUpserts;

class ProductImport implements ToModel, WithHeadingRow, WithUpserts
{
    public function model(array $row)
    {
        if (empty($row['name'])) {
            return null;
        }

        return new Product([
            'name' => $row['name'],
            'code' => $row['code'] ?? null,
            'barcode' => $row['barcode'] ?? null,
            'category_id' => $row['category_id'] ?? null,
            'brand' => $row['brand'] ?? null,
            'model_compatibility' => $row['model_compatibility'] ?? null,
            'cost_price' => $row['cost_price'] ?? 0,
            'selling_price' => $row['selling_price'] ?? 0,
            'wholesale_price' => $row['wholesale_price'] ?? 0,
            'rack_number' => $row['rack_number'] ?? null,
            'minimum_stock' => $row['minimum_stock'] ?? 0,
            'reorder_level' => $row['reorder_level'] ?? 0,
            'unit' => $row['unit'] ?? 'pcs',
            'shop_type' => $row['shop_type'] ?? 'workshop',
            'active' => 1,
        ]);
    }

    public function uniqueBy()
    {
        return 'code';
    }
}
