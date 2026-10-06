<?php

use App\Models\Account;
use App\Models\BikeIntake;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\JobCard;
use App\Models\Product;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Store;
use App\Models\StoreStock;
use App\Models\Technician;
use App\Models\User;

test('job card invoices preserve vehicle technician and service details in both paper formats', function () {
    $user = User::factory()->create();
    $customer = Customer::create(['name' => 'Workshop Customer', 'phone' => '0776000002', 'active' => true]);
    $bike = $customer->bikes()->create(['number' => 'WP BILL 100', 'model' => 'Yamaha FZ']);
    $intake = BikeIntake::create(['intake_number' => 'IN-DOC-001', 'bike_id' => $bike->id, 'complaint' => 'Service test', 'created_by' => $user->id]);
    $technician = Technician::create(['name' => 'Document Technician', 'phone' => '0776000003', 'active' => true]);
    $job = JobCard::create(['number' => 'JOB-DOC-001', 'bike_intake_id' => $intake->id, 'main_technician_id' => $technician->id, 'status' => 'Completed', 'priority' => 'Normal', 'created_by' => $user->id]);
    $invoice = Invoice::create(['number' => 'INV-DOC-JOB', 'job_card_id' => $job->id, 'customer_id' => $customer->id, 'invoice_type' => 'Job Card Bill', 'service_amount' => 700, 'subtotal' => 700, 'total' => 700, 'due_amount' => 700, 'public_token' => 'job-document-token', 'created_by' => $user->id]);
    $this->actingAs($user);

    foreach (['a4' => [595.28, 841.89], 'a5' => [419.53, 595.28]] as $format => [$width, $height]) {
        $this->get(route('invoices.public', ['token' => $invoice->public_token, 'format' => $format]))
            ->assertOk()->assertSeeText('Service Invoice')->assertSeeText('WP BILL 100')
            ->assertSeeText('Document Technician')->assertSeeText('JOB-DOC-001')->assertSeeText('700.00');
        $response = $this->get(route('sales.invoices.pdf', ['invoice' => $invoice, 'format' => $format]))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        preg_match('/\/MediaBox\s*\[\s*0(?:\.0+)?\s+0(?:\.0+)?\s+([\d.]+)\s+([\d.]+)\s*\]/', $response->getContent(), $box);
        expect(round((float) $box[1], 2))->toBe($width)->and(round((float) $box[2], 2))->toBe($height);
    }
});

test('quotation and bill have printable previews and downloadable PDFs', function () {
    $user = User::factory()->create();
    $store = Store::create(['name' => 'Document Store', 'active' => true]);
    $account = Account::create(['code' => 'DOC-BANK', 'name' => 'Document Bank', 'type' => 'bank', 'active' => true]);
    $product = Product::create(['code' => 'DOC-001', 'name' => 'Premium Brake Pad', 'cost_price' => 50, 'selling_price' => 125, 'active' => true]);
    StoreStock::create(['store_id' => $store->id, 'product_id' => $product->id, 'quantity' => 5]);

    foreach (['Quotation', 'Parts Sale'] as $type) {
        $payload = [
            'customer_mode' => 'walkin', 'store_id' => $store->id, 'invoice_type' => $type,
            'paid_amount' => $type === 'Quotation' ? 0 : 125,
            'payment_method' => $type === 'Quotation' ? null : 'Card',
            'account_id' => $type === 'Quotation' ? null : $account->id,
            'customer_note' => 'Customer-facing note', 'internal_note' => 'Staff-only note',
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 125]],
        ];
        $invoiceId = $this->actingAs($user)->postJson(route('sales.parts.store'), $payload)
            ->assertOk()->json('invoice_id');
        $invoice = Invoice::findOrFail($invoiceId);

        $this->get(route('invoices.public', $invoice->public_token))->assertOk()
            ->assertSeeText('Premium Brake Pad')->assertSeeText('Customer-facing note')
            ->assertDontSeeText('Staff-only note')->assertSeeText('Download PDF')
            ->assertSeeText('Yamaha Authorized Partner');

        foreach (['a4' => [595.28, 841.89], 'a5' => [419.53, 595.28]] as $format => [$width, $height]) {
            $this->get(route('invoices.public', ['token' => $invoice->public_token, 'format' => $format]))
                ->assertOk()->assertSee(route('invoices.public.pdf', ['token' => $invoice->public_token, 'format' => $format]), false);
            foreach ([route('sales.invoices.pdf', ['invoice' => $invoice, 'format' => $format]), route('invoices.public.pdf', ['token' => $invoice->public_token, 'format' => $format])] as $url) {
                $response = $this->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf');
                expect(substr($response->getContent(), 0, 4))->toBe('%PDF');
                preg_match('/\/MediaBox\s*\[\s*0(?:\.0+)?\s+0(?:\.0+)?\s+([\d.]+)\s+([\d.]+)\s*\]/', $response->getContent(), $box);
                expect(round((float) $box[1], 2))->toBe($width)->and(round((float) $box[2], 2))->toBe($height);
            }
        }
    }
});

test('public PDF access respects invoice download setting', function () {
    $user = User::factory()->create();
    $store = Store::create(['name' => 'Setting Store', 'active' => true]);
    $product = Product::create(['code' => 'DOC-002', 'name' => 'Setting Part', 'selling_price' => 20, 'active' => true]);
    $invoiceId = $this->actingAs($user)->postJson(route('sales.parts.store'), [
        'customer_mode' => 'walkin', 'store_id' => $store->id, 'invoice_type' => 'Quotation', 'paid_amount' => 0,
        'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 20]],
    ])->assertOk()->json('invoice_id');
    $invoice = Invoice::findOrFail($invoiceId);
    Setting::create(['group' => 'invoice', 'key' => 'public_pdf_download', 'value' => '0']);

    $this->get(route('invoices.public', $invoice->public_token))->assertOk()->assertDontSeeText('Download PDF');
    $this->get(route('invoices.public.pdf', $invoice->public_token))->assertForbidden();
    $this->get(route('sales.invoices.pdf', $invoice))->assertOk()->assertHeader('Content-Type', 'application/pdf');
});

test('POS cashier can download their own document without invoice-list access', function () {
    $role = Role::create(['name' => 'POS Cashier', 'permissions' => ['sales.parts_sales']]);
    $cashier = User::factory()->create(['role_id' => $role->id]);
    $otherCashier = User::factory()->create(['role_id' => $role->id]);
    $store = Store::create(['name' => 'Cashier Store', 'active' => true]);
    $product = Product::create(['code' => 'DOC-003', 'name' => 'Cashier Part', 'selling_price' => 50, 'active' => true]);

    $invoiceId = $this->actingAs($cashier)->postJson(route('sales.parts.store'), [
        'customer_mode' => 'walkin', 'store_id' => $store->id, 'invoice_type' => 'Quotation', 'paid_amount' => 0,
        'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 50]],
    ])->assertOk()->json('invoice_id');
    $invoice = Invoice::findOrFail($invoiceId);

    $this->get(route('sales.invoices.pdf', $invoice))->assertOk();
    $this->actingAs($otherCashier)->get(route('sales.invoices.pdf', $invoice))->assertForbidden();
});

test('invoice settings control quotation and bill wording and show the developer credit', function () {
    $user = User::factory()->create();
    $store = Store::create(['name' => 'Wording Store', 'active' => true]);
    $account = Account::create(['code' => 'WORD-BANK', 'name' => 'Wording Bank', 'type' => 'bank', 'active' => true]);
    $product = Product::create(['code' => 'WORD-001', 'name' => 'Wording Part', 'selling_price' => 100, 'active' => true]);
    StoreStock::create(['store_id' => $store->id, 'product_id' => $product->id, 'quantity' => 2]);

    $this->actingAs($user)->post(route('system-settings.save'), [
        'group' => 'invoice',
        'quotation_intro_heading' => 'Quote details',
        'quotation_intro_text' => 'Prices shown are estimates.',
        'quotation_default_note' => 'Quote default note',
        'bill_intro_heading' => 'Bill details',
        'bill_intro_text' => 'Please review your purchase.',
        'bill_default_note' => 'Bill default note',
        'customer_note_heading' => 'Customer message',
        'terms_heading' => 'Our terms',
        'terms_and_conditions' => 'Terms apply to both documents.',
        'invoice_footer_note' => 'Thank you for your visit.',
    ])->assertRedirect();
    $this->get(route('system-settings.invoice-settings'))->assertOk()
        ->assertSee('Prices shown are estimates.')->assertSee('Please review your purchase.');

    foreach (['Quotation', 'Parts Sale'] as $type) {
        $id = $this->postJson(route('sales.parts.store'), [
            'customer_mode' => 'walkin', 'store_id' => $store->id, 'invoice_type' => $type,
            'paid_amount' => $type === 'Quotation' ? 0 : 100,
            'payment_method' => $type === 'Quotation' ? null : 'Cash',
            'account_id' => $type === 'Quotation' ? null : $account->id,
            'customer_note' => $type === 'Quotation' ? null : 'A note entered for this bill',
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 100]],
        ])->assertOk()->json('invoice_id');
        $invoice = Invoice::findOrFail($id);
        $response = $this->get(route('invoices.public', $invoice->public_token))->assertOk()
            ->assertSeeText('Our terms')->assertSeeText('Terms apply to both documents.')
            ->assertSeeText('Thank you for your visit.')
            ->assertSeeText('Software developed by Twinsofte.com');
        if ($type === 'Quotation') {
            $response->assertSeeText('Quote details')->assertSeeText('Prices shown are estimates.')
                ->assertSeeText('Quote default note')->assertDontSeeText('Bill details');
        } else {
            $response->assertSeeText('Bill details')->assertSeeText('Please review your purchase.')
                ->assertSeeText('A note entered for this bill')->assertDontSeeText('Bill default note');
        }
        $this->get(route('sales.invoices.pdf', $invoice))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }
});
