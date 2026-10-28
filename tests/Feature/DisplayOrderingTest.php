<?php

use App\Models\Account;
use App\Models\BikeIntake;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\JobCard;
use App\Models\Product;
use App\Models\Store;
use App\Models\Technician;
use App\Models\TechnicianAdvance;
use App\Models\TechnicianBikeDeduction;
use App\Models\User;
use App\Support\InvoiceDocument;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    Account::ensureSystemAccounts();
});

test('staff history inserts backdated advances into date order with stable same-day ordering', function () {
    $staff = Technician::create(['name' => 'Ordering Staff', 'phone' => '0777000111', 'active' => true]);
    $advance = fn ($number, $date) => TechnicianAdvance::create([
        'number' => $number, 'technician_id' => $staff->id, 'account_id' => Account::systemAccount('main')->id,
        'payment_date' => $date, 'amount' => 2500, 'payment_method' => 'Cash', 'deduct_month' => '2026-10-01',
        'status' => 'Pending Deduction', 'created_by' => auth()->id(),
    ]);
    $advance('ADV-ORDER-LATE', '2026-10-26');
    $advance('ADV-ORDER-EARLY', '2026-10-05');
    $middle = $advance('ADV-ORDER-BACKDATED', '2026-10-15');
    $advance('ADV-ORDER-SAME-DAY', '2026-10-15');
    $advance('ADV-ORDER-EXCLUDED', '2026-09-15');
    $customer = Customer::create(['name' => 'History Bike Owner', 'phone' => '0777000222']);
    $bike = $customer->bikes()->create(['number' => 'ORDER-BIKE', 'model' => 'Honda']);
    TechnicianBikeDeduction::create([
        'number' => 'DED-ORDER', 'technician_id' => $staff->id, 'bike_id' => $bike->id,
        'total_amount' => 5000, 'deduction_type' => 'Monthly installment', 'start_month' => '2026-10-01',
        'status' => 'Active', 'created_by' => auth()->id(),
    ]);
    $url = route('technicians.history', ['technician' => $staff->id, 'month' => '2026-10']);
    $response = $this->get($url)->assertOk()->assertDontSeeText('ADV-ORDER-EXCLUDED');
    expect($response->viewData('financialHistory')->pluck('label')->all())->toBe([
        'Bike ORDER-BIKE', 'Advance ADV-ORDER-EARLY', 'Advance ADV-ORDER-BACKDATED',
        'Advance ADV-ORDER-SAME-DAY', 'Advance ADV-ORDER-LATE',
    ]);
    $response->assertSeeInOrder(['Bike ORDER-BIKE', 'Advance ADV-ORDER-EARLY', 'Advance ADV-ORDER-BACKDATED', 'Advance ADV-ORDER-SAME-DAY', 'Advance ADV-ORDER-LATE']);
    $middle->update(['payment_date' => '2026-10-03']);
    $this->get($url)->assertOk()->assertSeeInOrder(['Bike ORDER-BIKE', 'Advance ADV-ORDER-BACKDATED', 'Advance ADV-ORDER-EARLY', 'Advance ADV-ORDER-SAME-DAY', 'Advance ADV-ORDER-LATE']);
});

test('staff history keeps its empty state when there are no financial entries', function () {
    $staff = Technician::create(['name' => 'Empty Ordering Staff', 'phone' => '0777000333', 'active' => true]);
    $this->get(route('technicians.history', ['technician' => $staff->id, 'month' => '2026-10']))->assertOk()
        ->assertSeeText('No advances or bike deductions.')->assertViewHas('financialHistory', fn ($entries) => $entries->isEmpty());
});

test('job card product order is preserved in invoice details and every bill format after renaming a line', function () {
    $customer = Customer::create(['name' => 'Order Customer', 'phone' => '0777000444']);
    $bike = $customer->bikes()->create(['number' => 'ORDER-1234', 'model' => 'Honda']);
    $intake = BikeIntake::create(['intake_number' => 'IN-ORDER', 'bike_id' => $bike->id, 'complaint' => 'Order check', 'created_by' => auth()->id()]);
    $job = JobCard::create(['number' => 'JOB-ORDER', 'bike_intake_id' => $intake->id, 'status' => 'Billed', 'parts_total' => 300, 'service_total' => 50, 'created_by' => auth()->id()]);
    $store = Store::create(['name' => 'Order Store', 'active' => true]);
    foreach (['Zebra chain', 'Alpha plug', 'Middle pad'] as $index => $name) {
        $product = Product::create(['code' => 'ORDER-'.$index, 'name' => $name, 'cost_price' => 50, 'selling_price' => 100, 'active' => true]);
        $job->parts()->create(['product_id' => $product->id, 'store_id' => $store->id, 'quantity' => 1, 'unit_price' => 100, 'cost_price' => 50, 'total' => 100, 'profit' => 50]);
    }
    $job->parts()->oldest('id')->firstOrFail()->update(['display_name' => 'Zulu renamed chain', 'note' => 'First added']);
    $invoice = Invoice::create(['number' => 'INV-ORDER', 'invoice_type' => 'Job Card Bill', 'job_card_id' => $job->id, 'customer_id' => $customer->id, 'bike_id' => $bike->id, 'parts_amount' => 300, 'service_amount' => 50, 'subtotal' => 350, 'total' => 350, 'due_amount' => 350, 'status' => 'Unpaid', 'public_token' => 'order-document-token', 'created_by' => auth()->id()]);
    $names = ['Zulu renamed chain (First added)', 'Alpha plug', 'Middle pad'];
    $html = $this->get(route('jobs.show', $job))->assertOk()->getContent();
    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="UTF-8">'.$html);
    $xpath = new DOMXPath($document);
    $jobNames = [];
    foreach ($xpath->query('//*[@id="spare-parts"]//tbody/tr/td[1]') as $cell) {
        $jobNames[] = preg_replace('/^\d+\.\s*/', '', trim($cell->textContent));
    }
    expect($jobNames)->toBe($names);
    // Loaded relation order must not affect the public document's numbering.
    $job->setRelation('parts', $job->parts()->orderByDesc('id')->get());
    $invoice->setRelation('jobCard', $job);
    foreach ([false, true] as $pdfMode) {
        expect(collect(InvoiceDocument::data($invoice, pdfMode: $pdfMode)['lines'])->pluck('name')->all())->toBe([...$names, 'Service']);
    }
    foreach (['a4', 'a5', '80mm'] as $format) {
        $this->get(route('invoices.public', ['token' => $invoice->public_token, 'format' => $format]))->assertOk()->assertSeeInOrder($names);
    }
    $this->get(route('sales.invoices.show', $invoice))->assertOk()->assertSeeInOrder($names);
});
