<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\StockBatch;
use App\Models\Store;
use App\Models\StoreStock;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PosSampleDataSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            // Ensure we have a main store
            $mainStore = Store::firstOrCreate(
                ['name' => 'Main Store'],
                ['location' => 'Main Building', 'is_default' => true, 'active' => true]
            );

            // 1. Categories
            $categories = [
                'Brake Parts' => 'brake',
                'Engine Oil' => 'oil',
                'Chains & Sprockets' => 'chain',
                'Spark Plugs' => 'spark',
                'Filters' => 'filter',
                'Cables' => 'cable',
                'Tyres' => 'tyre',
                'Accessories' => 'accessory',
            ];

            $catMap = [];
            foreach ($categories as $name => $icon) {
                $catMap[$name] = ProductCategory::firstOrCreate(
                    ['name' => $name],
                    ['description' => "Category for $name"]
                );
            }

            // 2. Products Definition
            $items = [
                ['name' => 'Brake Pad Front Yamaha FZ-S', 'sku' => 'BP-FZS-001', 'price' => 1450, 'unit' => 'Set', 'cat' => 'Brake Parts', 'batches' => [[18, 1000, 1450]]],
                ['name' => 'Engine Oil 20W50 Shell Advance 4T', 'sku' => 'EO-SH20W50-1L', 'price' => 1950, 'unit' => '1 Ltr', 'cat' => 'Engine Oil', 'batches' => [[10, 1500, 1950], [16, 1600, 2050]]], // MULTIPLE BATCHES
                ['name' => 'Chain Kit Honda CBZ Xtreme', 'sku' => 'CK-HNZ-001', 'price' => 3250, 'unit' => 'Kit', 'cat' => 'Chains & Sprockets', 'batches' => [[7, 2500, 3250]]],
                ['name' => 'Spark Plug NGK CR8E', 'sku' => 'SP-NGK-CR8E', 'price' => 650, 'unit' => 'Each', 'cat' => 'Spark Plugs', 'batches' => [[42, 400, 650]]],
                ['name' => 'Clutch Cable Pulsar 150', 'sku' => 'CC-PLSR150', 'price' => 890, 'unit' => 'Each', 'cat' => 'Cables', 'batches' => [[15, 600, 890]]],
                ['name' => 'Air Filter Element Hero Splendor', 'sku' => 'AF-HSPL-001', 'price' => 520, 'unit' => 'Each', 'cat' => 'Filters', 'batches' => [[29, 300, 520]]],
                ['name' => 'Rear Brake Shoes Bajaj Discover 125', 'sku' => 'RB-BJD125', 'price' => 680, 'unit' => 'Set', 'cat' => 'Brake Parts', 'batches' => [[4, 450, 680]]], // LOW STOCK
                ['name' => 'Engine Oil 10W30 Castrol Activ 4T', 'sku' => 'EO-CAST10W30-1L', 'price' => 1450, 'unit' => '1 Ltr', 'cat' => 'Engine Oil', 'batches' => [[12, 1000, 1450]]],
                ['name' => 'Drive Chain 428H Heavy Duty', 'sku' => 'DC-428H-118L', 'price' => 2200, 'unit' => 'Each', 'cat' => 'Chains & Sprockets', 'batches' => [[8, 1600, 2200]]], // LOW STOCK
                ['name' => 'Disc Rotor Front Pulsar 220', 'sku' => 'DR-PLSR220-F', 'price' => 2750, 'unit' => 'Each', 'cat' => 'Brake Parts', 'batches' => [[5, 2000, 2750]]], // LOW STOCK
                ['name' => 'Indicator Bulb 12V 21W Amber', 'sku' => 'IB-12V21W-AM', 'price' => 120, 'unit' => 'Pack', 'cat' => 'Accessories', 'batches' => [[0, 80, 120]]], // OUT OF STOCK
                ['name' => 'Battery 12V 5Ah GS GTZ5S', 'sku' => 'BT-GSZ5S', 'price' => 4250, 'unit' => 'Each', 'cat' => 'Accessories', 'batches' => [[11, 3500, 4250]]],
                ['name' => 'Oil Filter Yamaha R15', 'sku' => 'OF-YR15-001', 'price' => 380, 'unit' => 'Each', 'cat' => 'Filters', 'batches' => [[20, 200, 380]]],
                ['name' => 'Front Sprocket 14T Pulsar 150', 'sku' => 'FS-PLSR150-14T', 'price' => 480, 'unit' => 'Each', 'cat' => 'Chains & Sprockets', 'batches' => [[14, 300, 480]]],
                ['name' => 'Throttle Cable Discover 125', 'sku' => 'TC-DISC125', 'price' => 750, 'unit' => 'Each', 'cat' => 'Cables', 'batches' => [[6, 450, 750]]], // LOW STOCK
                ['name' => 'Fork Oil 10W Motul 1L', 'sku' => 'FO-MOTUL10W-1L', 'price' => 1250, 'unit' => '1 Ltr', 'cat' => 'Engine Oil', 'batches' => [[9, 850, 1250]]],
            ];

            foreach ($items as $index => $data) {
                // Determine lowest selling price for base product
                $baseCost = $data['batches'][0][1];
                $baseSelling = $data['batches'][0][2];
                $totalQty = collect($data['batches'])->sum(0);

                $product = Product::firstOrCreate(
                    ['code' => $data['sku']],
                    [
                        'name' => $data['name'],
                        'category_id' => $catMap[$data['cat']]->id,
                        'unit' => $data['unit'],
                        'cost_price' => $baseCost,
                        'selling_price' => $baseSelling,
                        'minimum_stock' => 8,
                        'active' => true,
                    ]
                );

                // Setup Total Stock
                StoreStock::updateOrCreate(
                    ['product_id' => $product->id, 'store_id' => $mainStore->id],
                    ['quantity' => $totalQty]
                );

                // Ensure Batches exist
                foreach ($data['batches'] as $i => $batchData) {
                    [$qty, $cost, $selling] = $batchData;

                    if ($qty == 0) {
                        // For OOS, we just create an empty batch for history
                        StockBatch::firstOrCreate([
                            'product_id' => $product->id,
                            'store_id' => $mainStore->id,
                            'batch_no' => 'B-'.str_pad($index + 1, 3, '0', STR_PAD_LEFT).'-'.($i + 1),
                        ], [
                            'cost_price' => $cost,
                            'selling_price' => $selling,
                            'purchased_qty' => 10,
                            'available_qty' => 0,
                            'purchase_date' => now()->subDays(rand(10, 30)),
                            'status' => 'empty',
                        ]);
                    } else {
                        StockBatch::firstOrCreate([
                            'product_id' => $product->id,
                            'store_id' => $mainStore->id,
                            'batch_no' => 'B-'.str_pad($index + 1, 3, '0', STR_PAD_LEFT).'-'.($i + 1),
                        ], [
                            'cost_price' => $cost,
                            'selling_price' => $selling,
                            'purchased_qty' => $qty,
                            'available_qty' => $qty,
                            'purchase_date' => now()->subDays(rand(1, 60)),
                            'status' => 'active',
                        ]);
                    }
                }
            }
        });
    }
}
