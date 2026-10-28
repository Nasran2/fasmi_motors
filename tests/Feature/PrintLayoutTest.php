<?php

use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\User;
use App\Services\AccountService;
use App\Support\PrintDocument;

beforeEach(function () {
    $this->actingAs(User::factory()->create(['name' => 'Print Review Admin']));
});

test('every report has a standalone print document with no application navigation or forms', function ($report) {
    $response = $this->get(route('reports.'.$report, ['print' => 1, 'preview' => 1]))->assertOk()->assertViewIs('printing.document')
        ->assertSee('data-print-document', false)->assertDontSee('workshop-shell', false)->assertDontSee('workshop-sidebar', false)
        ->assertDontSee('<form', false)->assertDontSee('tom-select', false);
    $doc = new DOMDocument;
    @$doc->loadHTML($response->getContent());
    $xpath = new DOMXPath($doc);
    expect($xpath->query('//table[contains(@class,"document-table")]')->length)->toBeGreaterThan(0)
        ->and($xpath->query('//tfoot')->length)->toBe(0);
})->with(['index', 'job-cards', 'spare-parts', 'profit-loss', 'stock', 'purchases', 'expenses', 'drawings', 'due-bills', 'customer-due', 'technicians', 'attendance', 'salary', 'epf', 'etf', 'main-account', 'spare-parts-account', 'bank-account', 'daily-closing', 'service-reminders', 'sms', 'collections', 'supplier-dues', 'sales-returns', 'cash-book']);

test('print document removes action columns and active controls while preserving escaped customer text', function () {
    $html = '<html><head><title>Sample Report</title></head><body><table id="reportTable"><thead><tr><th>Customer</th><th>Total</th><th>Actions</th></tr></thead><tbody><tr><td>Müller &amp; Sons<script>alert(1)</script><img src="x" onerror="alert(1)"></td><td class="text-right">1,200.25</td><td><form><button>Delete</button></form></td></tr></tbody><tfoot><tr><td>Closing</td><td>1,200.25</td><td></td></tr></tfoot></table></body></html>';
    $document = PrintDocument::fromHtml($html);
    $table = $document['sections'][0]['html'];
    expect($table)->toContain('Müller &amp; Sons', '1,200.25', 'document-totals')->not->toContain('<tfoot', '<script', '<form', '<button', 'onerror', '<img', 'Actions');
});

test('cash book print contains every entry in order and one final closing row', function () {
    Account::systemAccount('main')->update(['opening_balance' => 1304040]);
    $rows = [];
    foreach (range(1, 75) as $i) {
        $rows[] = ['transaction_no' => 'PRINT-'.str_pad((string) $i, 3, '0', STR_PAD_LEFT), 'transaction_date' => now(), 'account_type' => 'main', 'account_id' => Account::systemAccount('main')->id, 'transaction_type' => $i % 3 ? 'income' : 'expense', 'credit' => $i % 3 ? 11160 : 0, 'debit' => $i % 3 ? 0 : 3455.09, 'amount' => $i % 3 ? 11160 : 3455.09, 'payment_method' => 'Cash', 'description' => 'Receipt for INV-20261028-'.str_pad((string) $i, 4, '0', STR_PAD_LEFT).' - service and spare parts', 'status' => 'active', 'created_by' => auth()->id(), 'created_at' => now(), 'updated_at' => now()];
    }
    AccountTransaction::insert($rows);
    $response = $this->get(route('reports.cash-book', ['print' => 1, 'preview' => 1, 'page' => 2]))->assertOk()->assertSeeInOrder(['PRINT-001', 'PRINT-075', 'Closing']);
    $doc = new DOMDocument;
    @$doc->loadHTML($response->getContent());
    $xpath = new DOMXPath($doc);
    expect($xpath->query('//tbody[contains(@class,"document-totals")]/tr')->length)->toBe(2)
        ->and($xpath->query('//td[normalize-space(.)="Closing"]')->length)->toBe(1)
        ->and($xpath->query('//tfoot')->length)->toBe(0);
    if ($directory = getenv('PRINT_QA_DIR')) {
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
        file_put_contents($directory.'/cash-book.html', $response->getContent());
        $document = $response->viewData('document');
        file_put_contents($directory.'/cash-book.pdf', PrintDocument::pdf('printing.document', ['document' => $document], $document['orientation'])->output());
    }
});

test('print summaries and applied filters are preserved without screen cards', function () {
    app(AccountService::class)->recordTransaction('main', 'income', 1250, 'Print receipt', paymentMethod: 'Cash');
    $response = $this->get(route('reports.cash-book', ['print' => 1, 'preview' => 1, 'direction' => 'in']))->assertOk();
    $document = $response->viewData('document');
    expect($document['filters'])->toContain('Cash direction: Cash in')
        ->and(collect($document['summary'])->pluck('value')->all())->toContain('Rs. 1,250.00');
    $response->assertDontSee('class="panel', false)->assertSee('document-summary', false);
});

test('generated PDF documents keep printable paper margins', function () {
    $pdf = PrintDocument::pdf('finance-management.report', ['rows' => collect(), 'module' => 'expense', 'from' => today()->toDateString(), 'to' => today()->toDateString()]);
    $page = $pdf->getDomPDF()->getCss()->get_page_styles()['base'];
    expect($page->margin_left)->toBeGreaterThan(30)->and($page->margin_top)->toBeGreaterThan(30)->and($pdf->output())->toStartWith('%PDF-');
});

test('wide print tables use landscape paper and retain long references and large amounts', function () {
    $headings = ['Date', 'Invoice No', 'Invoice Type', 'Customer', 'Bike Number', 'Total Amount', 'Paid Amount', 'Held Amount', 'Due Amount', 'Payment Method', 'Status'];
    $values = ['28 Oct 2026 03:07 PM', 'INV-20261028-00123456', 'Job Card Bill', 'Sample Customer With A Long Registered Business Name', 'BGS-3840', 'Rs. 123,456,789.99', 'Rs. 100,000,000.00', 'Rs. 3,456,789.99', 'Rs. 20,000,000.00', 'Bank Transfer', 'Partially Paid / Hold'];
    $html = '<html><head><title>Sales Report</title></head><body><table id="reportTable"><thead><tr>'.collect($headings)->map(fn ($h) => '<th>'.e($h).'</th>')->join('').'</tr></thead><tbody><tr>'.collect($values)->map(fn ($v) => '<td>'.e($v).'</td>')->join('').'</tr></tbody></table></body></html>';
    $document = PrintDocument::fromHtml($html);
    expect($document['orientation'])->toBe('landscape')->and($document['sections'][0]['html'])->toContain('document-wide', '<colgroup>', '123,456,789.99');
    if ($directory = getenv('PRINT_QA_DIR')) {
        file_put_contents($directory.'/wide-sales.pdf', PrintDocument::pdf('printing.document', ['document' => $document], $document['orientation'])->output());
    }
});
