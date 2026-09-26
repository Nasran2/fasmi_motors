<?php
namespace App\Exports;

use App\Models\Customer;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class CustomerExport implements FromCollection, WithHeadings, WithMapping
{
    public function collection()
    {
        return Customer::all();
    }

    public function headings(): array
    {
        return [
            'Name',
            'Phone',
            'Alternative Phone',
            'NIC',
            'Email',
            'Customer Type',
            'Address',
            'Shop Type'
        ];
    }

    public function map($customer): array
    {
        return [
            $customer->name,
            $customer->phone,
            $customer->alternative_phone,
            $customer->nic,
            $customer->email,
            $customer->customer_type,
            $customer->address,
            $customer->shop_type
        ];
    }
}
