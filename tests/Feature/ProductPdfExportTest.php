<?php

use App\Models\Product;
use App\Models\Store;
use App\Models\StoreStock;
use App\Models\User;
use App\Services\ProductPdfExport;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    $this->seedProducts = function (int $count, array $overrides = []) {
        $rows = [];
        for ($i = 1; $i <= $count; $i++) {
            $rows[] = array_replace(['name' => sprintf('Export Part %04d', $i), 'code' => sprintf('PDF-%04d', $i), 'brand' => 'Export Brand', 'unit' => 'Piece', 'rack_number' => sprintf('A%04d', $i), 'cost_price' => 100, 'selling_price' => 175, 'minimum_stock' => 0, 'active' => true, 'shop_type' => 'workshop', 'created_at' => now(), 'updated_at' => now()], $overrides);
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            Product::insert($chunk);
        }
    };
});

test('product export popup shows the filtered catalog PDF page count', function () {
    ($this->seedProducts)(41);
    Product::create(['code' => 'OTHER', 'name' => 'Another brand', 'brand' => 'Other Brand', 'active' => true]);
    $this->get(route('products.index', ['brand' => 'Export Brand', 'page' => 2]))->assertOk()
        ->assertViewHas('productPdf', ['total' => 41, 'pages' => 3, 'perPage' => 20, 'recommendedPages' => 20])
        ->assertSeeText('Total PDF Pages')->assertSeeText('From Page')->assertSeeText('To Page')
        ->assertSeeText('You can download any valid range, including all pages.')
        ->assertSeeText('You can still download this range.');
});

test('large product catalog only loads the selected range and downloads an A4 PDF', function () {
    ($this->seedProducts)(2940);
    DB::enableQueryLog();
    $response = $this->get(route('products.pdf.export', ['columns' => ['name', 'rack_number'], 'from_page' => 2, 'to_page' => 3]))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect($response->headers->get('Content-Disposition'))->toContain('attachment', 'products-pages-2-3.pdf');
    $pdf = $response->getContent();
    expect(substr($pdf, 0, 4))->toBe('%PDF')->and($pdf)->toMatch('/\/Count\s+2\b/')->toMatch('/\/MediaBox\s*\[0\.000\s+0\.000\s+595\.280\s+841\.890\]/');
    $selects = collect(DB::getQueryLog())->pluck('query')->filter(fn ($sql) => str_contains($sql, 'stocks_sum_quantity'));
    expect($selects->count())->toBe(1)->and($selects->first())->toContain('limit 40 offset 20');
    DB::disableQueryLog();
});

test('PDF range boundaries honor quantity order filters and current shop', function () {
    ($this->seedProducts)(41);
    Product::create(['name' => 'Other shop part', 'code' => 'HELMET-PDF', 'brand' => 'Export Brand', 'shop_type' => 'helmet', 'active' => true]);
    $store = Store::create(['name' => 'Export Store', 'active' => true]);
    $first = Product::where('code', 'PDF-0001')->first();
    StoreStock::create(['product_id' => $first->id, 'store_id' => $store->id, 'quantity' => -5]);
    $this->partialMock(ProductPdfExport::class, function ($mock) {
        $mock->shouldReceive('render')->once()->withArgs(function ($products, $columns, $from, $to, $total) {
            expect($products->pluck('code')->all())->toBe(['PDF-0021', 'PDF-0022', 'PDF-0023', 'PDF-0024', 'PDF-0025', 'PDF-0026', 'PDF-0027', 'PDF-0028', 'PDF-0029', 'PDF-0030', 'PDF-0031', 'PDF-0032', 'PDF-0033', 'PDF-0034', 'PDF-0035', 'PDF-0036', 'PDF-0037', 'PDF-0038', 'PDF-0039', 'PDF-0040']);
            expect($columns)->toBe(['name', 'qty'])->and($from)->toBe(2)->and($to)->toBe(2)->and($total)->toBe(41);

            return true;
        })->andReturn('%PDF-test');
    });
    $this->get(route('products.pdf.export', ['columns' => ['name', 'qty'], 'from_page' => 2, 'to_page' => 2, 'sort' => 'qty_asc', 'brand' => 'Export Brand', 'status' => 'low']))->assertOk();
});

test('wide exports use A4 landscape and the final page contains only remaining products', function () {
    ($this->seedProducts)(41);
    $pdf = $this->get(route('products.pdf.export', ['columns' => array_keys(ProductPdfExport::COLUMNS), 'from_page' => 3, 'to_page' => 3]))->assertOk()->getContent();
    expect($pdf)->toMatch('/\/Count\s+1\b/')->toMatch('/\/MediaBox\s*\[0\.000\s+0\.000\s+841\.890\s+595\.280\]/');
});

test('empty results and invalid page ranges or columns return validation errors', function () {
    $this->getJson(route('products.pdf.export'))->assertUnprocessable()->assertJsonValidationErrors('products');
    ($this->seedProducts)(421);
    foreach ([['from_page' => 0, 'to_page' => 1], ['from_page' => 2, 'to_page' => 1], ['from_page' => 1, 'to_page' => 23], ['from_page' => 1.5, 'to_page' => 2], ['columns' => []], ['columns' => ['unknown']], ['columns' => ['name', 'name']], ['sort' => 'id desc'], ['q' => ['malformed']]] as $parameters) {
        $this->json('GET', route('products.pdf.export'), $parameters)->assertUnprocessable();
    }
});

test('exports over the recommended page count can download the full catalog', function () {
    ($this->seedProducts)(421);
    $response = $this->get(route('products.pdf.export', ['columns' => ['name', 'rack_number'], 'from_page' => 1, 'to_page' => 22]))
        ->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect($response->headers->get('Content-Disposition'))->toContain('products-pages-1-22.pdf');
    expect($response->getContent())->toMatch('/\/Count\s+22\b/')->toMatch('/\/MediaBox\s*\[0\.000\s+0\.000\s+595\.280\s+841\.890\]/');
});

test('older export links default to a bounded range and rack ordering stays deterministic', function () {
    ($this->seedProducts)(140);
    $this->partialMock(ProductPdfExport::class, function ($mock) {
        $mock->shouldReceive('render')->once()->withArgs(function ($products, $columns, $from, $to, $total) {
            expect($products->count())->toBe(100)->and($from)->toBe(1)->and($to)->toBe(5)->and($total)->toBe(140)->and($products->first()->rack_number)->toBe('A0001')->and($products->last()->rack_number)->toBe('A0100');

            return true;
        })->andReturn('%PDF-test');
    });
    $this->get(route('products.pdf.export', ['sort' => 'rack_number']))->assertOk();
});
