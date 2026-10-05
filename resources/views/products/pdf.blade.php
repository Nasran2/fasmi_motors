<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Products List</title>
    <style>
        body { font-family: sans-serif; font-size: 12px; }
        table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        th, td { border: 1px solid #ddd; padding: 6px; text-align: left; }
        th { background-color: #f8f9fa; font-weight: bold; }
        .text-right { text-align: right; }
        h2 { text-align: center; margin-bottom: 20px; }
    </style>
</head>
<body>

    <h2>Products & Stock List</h2>

    <table>
        <thead>
            <tr>
                @if(in_array('name', $columns)) <th>Product Name</th> @endif
                @if(in_array('code', $columns)) <th>SKU/Barcode</th> @endif
                @if(in_array('rack_number', $columns)) <th>Rack No</th> @endif
                @if(in_array('brand', $columns)) <th>Brand</th> @endif
                @if(in_array('qty', $columns)) <th>Qty</th> @endif
                @if(in_array('cost', $columns)) <th class="text-right">Cost</th> @endif
                @if(in_array('sell', $columns)) <th class="text-right">Sell</th> @endif
                @if(in_array('status', $columns)) <th>Status</th> @endif
            </tr>
        </thead>
        <tbody>
            @foreach($products as $product)
                @php $qty = (float)($product->stocks_sum_quantity ?? 0); @endphp
                <tr>
                    @if(in_array('name', $columns)) <td>{{ $product->name }}</td> @endif
                    @if(in_array('code', $columns)) <td>{{ $product->code }} {{ $product->barcode ? ' / '.$product->barcode : '' }}</td> @endif
                    @if(in_array('rack_number', $columns)) <td>{{ $product->rack_number ?: '-' }}</td> @endif
                    @if(in_array('brand', $columns)) <td>{{ $product->brand ?: '-' }}</td> @endif
                    @if(in_array('qty', $columns)) <td>{{ number_format($qty, 2) }} {{ $product->unit }}</td> @endif
                    @if(in_array('cost', $columns)) <td class="text-right">{{ number_format($product->cost_price, 2) }}</td> @endif
                    @if(in_array('sell', $columns)) <td class="text-right">{{ number_format($product->selling_price, 2) }}</td> @endif
                    @if(in_array('status', $columns)) 
                        <td>
                            {{ $qty <= 0 ? 'Out of stock' : ($qty <= $product->minimum_stock ? 'Low stock' : 'In stock') }}
                        </td> 
                    @endif
                </tr>
            @endforeach
        </tbody>
    </table>

</body>
</html>
