<?php
namespace App\Imports;

use App\Models\Customer;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithUpserts;

class CustomerImport implements ToModel, WithHeadingRow, WithUpserts
{
    public function model(array $row)
    {
        // Require at least name and phone
        if (empty($row['name']) || empty($row['phone'])) {
            return null;
        }

        return new Customer([
            'name' => $row['name'],
            'phone' => $row['phone'],
            'alternative_phone' => $row['alternative_phone'] ?? null,
            'nic' => $row['nic'] ?? null,
            'email' => $row['email'] ?? null,
            'customer_type' => $row['customer_type'] ?? 'Regular',
            'address' => $row['address'] ?? null,
            'shop_type' => $row['shop_type'] ?? 'workshop',
            'active' => 1,
        ]);
    }

    public function uniqueBy()
    {
        return 'phone';
    }
}
