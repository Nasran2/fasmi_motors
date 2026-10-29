<?php

use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\BikeIntake;
use App\Models\Expense;
use App\Models\JobCard;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreStock;
use App\Models\Technician;
use App\Models\User;

beforeEach(function () {
    $this->actingAs(User::factory()->create())->withOpenCashier();
    Account::ensureSystemAccounts();
    $this->store = Store::create(['name' => 'Main Store', 'active' => true, 'is_default' => true]);
    $this->post(route('intakes.store'), ['bike_number' => 'OUT-2255', 'bike_model' => 'Honda Dio', 'phone' => '0771111122', 'customer_name' => 'Part Customer', 'complaint' => 'Test repair'])->assertSessionHasNoErrors();
    $tech = Technician::create(['name' => 'Nimal Silva', 'phone' => '0771234999', 'active' => true]);
    $this->post(route('jobs.store'), ['bike_intake_id' => BikeIntake::first()->id, 'main_technician_id' => $tech->id, 'priority' => 'Normal'])->assertSessionHasNoErrors();
    $this->job = JobCard::first();
    $this->outerData = ['display_name' => "Outer O'Ring", 'cost_price' => 60, 'unit_price' => 100, 'account_id' => Account::systemAccount('main')->id, 'note' => 'Genuine'];
    $this->balance = fn ($type) => Account::systemAccount($type)->balance;
});

test('job header title and parts section render before work details and products sort by their full names', function () {
    foreach (['Zebra', 'Abd Cable', 'Aba Cable', 'aba Band', 'ABC Cover'] as $index => $name) {
        Product::create(['code' => 'SORT-'.$index, 'name' => $name, 'cost_price' => 10, 'selling_price' => 20, 'active' => true]);
    }
    $response = $this->get(route('jobs.show', $this->job))->assertOk()->assertSeeInOrder(['id="spare-parts"', 'Work details'], false)->assertSeeText('Add New Product')->assertSeeText('Add Outer Products')->assertSeeInOrder(['aba Band —', 'Aba Cable —', 'ABC Cover —', 'Abd Cable —', 'Zebra —'])->assertSeeText('Cost / unit:');
    preg_match('/<title>(.*?)<\/title>/s', $response->getContent(), $title);
    expect(trim($title[1]))->toContain('OUT-2255 · Nimal Silva · '.$this->job->number);
});

test('stock part notes names and cost overrides stay on the job and appear on the invoice', function () {
    $product = Product::create(['code' => 'NOTE-001', 'name' => 'Brake Pad', 'cost_price' => 40, 'selling_price' => 100, 'active' => true]);
    StoreStock::create(['store_id' => $this->store->id, 'product_id' => $product->id, 'quantity' => 5]);
    $this->post(route('jobs.parts.store', $this->job), ['product_id' => $product->id, 'store_id' => $this->store->id, 'quantity' => 1, 'unit_price' => 100, 'note' => 'Front wheel'])->assertSessionHasNoErrors();
    $part = $this->job->parts()->first();
    expect($part->bill_name)->toBe('Brake Pad (Front wheel)')->and((float) $part->cost_price)->toBe(40.0);
    $this->post(route('jobs.parts.update', ['job' => $this->job, 'part' => $part]), ['display_name' => "Brake Pad O'Ring", 'note' => 'Original', 'cost_price' => 45, 'quantity' => 2, 'unit_price' => 110])->assertSessionHasNoErrors();
    expect($part->fresh()->bill_name)->toBe("Brake Pad O'Ring (Original)")->and((float) $part->fresh()->profit)->toBe(130.0)->and($product->fresh()->name)->toBe('Brake Pad')->and((float) $product->fresh()->cost_price)->toBe(40.0)->and((float) StoreStock::first()->quantity)->toBe(3.0);
    $this->post(route('jobs.invoice', $this->job), ['paid_amount' => 0, 'payment_method' => 'Credit/Due'])->assertSessionHasNoErrors();
    $invoice = $this->job->fresh()->invoice;
    $this->get(route('invoices.public', $invoice->public_token))->assertOk()->assertSeeText("Brake Pad O'Ring (Original)");
    $this->get(route('sales.invoices.show', $invoice))->assertOk()->assertSeeText("Brake Pad O'Ring (Original)");
});

test('outer product defaults to one and immediately creates a linked expense without inventory', function () {
    $this->post(route('jobs.parts.outer', $this->job), $this->outerData)->assertSessionHasNoErrors();
    $part = $this->job->parts()->first();
    $expense = Expense::first();
    expect($part->is_external)->toBeTrue()->and($part->product_id)->toBeNull()->and($part->store_id)->toBeNull()->and((float) $part->quantity)->toBe(1.0)->and((float) $part->profit)->toBe(40.0)->and((float) $this->job->fresh()->parts_total)->toBe(100.0)->and(Product::count())->toBe(0)->and(StoreStock::count())->toBe(0)->and((float) $expense->amount)->toBe(60.0)->and($expense->account_id)->toBe(Account::systemAccount('main')->id)->and($expense->reference_number)->toContain('OUT-2255', $this->job->number)->and(($this->balance)('main'))->toBe(-60.0);
    $this->get(route('jobs.show', $this->job))->assertOk()->assertSeeText("Outer O'Ring (Genuine)")->assertSeeText('Outer Product');
    $this->post(route('jobs.invoice', $this->job), ['paid_amount' => 100, 'payment_method' => 'Cash'])->assertSessionHasNoErrors();
    expect(($this->balance)('spare_parts'))->toBe(100.0)->and(($this->balance)('main'))->toBe(-60.0);
    $this->get(route('invoices.public', $this->job->fresh()->invoice->public_token))->assertOk()->assertSeeText("Outer O'Ring (Genuine)");
    $this->get(route('reports.profit-loss'))->assertOk()->assertViewHas('partsCost', 0)->assertViewHas('totalExpenses', 60)->assertViewHas('netProfit', 40);
});

test('outer product edit and deletion reverse ledger costs without posting stock', function () {
    $this->post(route('jobs.parts.outer', $this->job), $this->outerData + ['quantity' => 2])->assertSessionHasNoErrors();
    $part = $this->job->parts()->first();
    expect(($this->balance)('main'))->toBe(-120.0);
    $edit = ['display_name' => 'Replacement Seal', 'note' => 'Rear', 'cost_price' => 70, 'unit_price' => 120, 'quantity' => 3, 'account_id' => Account::systemAccount('bank')->id];
    $this->post(route('jobs.parts.update', ['job' => $this->job, 'part' => $part]), $edit)->assertSessionHasNoErrors();
    expect(($this->balance)('main'))->toBe(0.0)->and(($this->balance)('bank'))->toBe(-210.0)->and((float) $part->fresh()->profit)->toBe(150.0)->and(Expense::count())->toBe(1)->and((float) Expense::first()->amount)->toBe(210.0)->and(StoreStock::count())->toBe(0);
    $count = AccountTransaction::count();
    $this->post(route('jobs.parts.update', ['job' => $this->job, 'part' => $part]), $edit)->assertSessionHasNoErrors();
    expect(AccountTransaction::count())->toBe($count);
    $this->delete(route('jobs.parts.destroy', ['job' => $this->job, 'part' => $part]))->assertSessionHasNoErrors();
    expect(($this->balance)('bank'))->toBe(0.0)->and(Expense::first()->status)->toBe('Cancelled')->and((float) $this->job->fresh()->parts_total)->toBe(0.0)->and(StoreStock::count())->toBe(0);
});

test('paid invoice prevents outer cost and quantity changes atomically when reopened', function () {
    $this->post(route('jobs.parts.outer', $this->job), $this->outerData)->assertSessionHasNoErrors();
    $part = $this->job->parts()->first();
    $this->post(route('jobs.invoice', $this->job), ['paid_amount' => 100, 'payment_method' => 'Cash'])->assertSessionHasNoErrors();
    $this->post(route('jobs.unlock', $this->job), ['status' => 'Diagnosis', 'reason' => 'Review outer part'])->assertSessionHasNoErrors();
    $this->post(route('jobs.parts.update', ['job' => $this->job, 'part' => $part]), ['display_name' => 'Changed', 'cost_price' => 99, 'unit_price' => 50, 'quantity' => 1])->assertSessionHasErrors('total');
    expect((float) $part->fresh()->cost_price)->toBe(60.0)->and((float) Expense::first()->amount)->toBe(60.0)->and(($this->balance)('main'))->toBe(-60.0);
    $this->delete(route('jobs.parts.destroy', ['job' => $this->job, 'part' => $part]))->assertSessionHasErrors('total');
    expect($this->job->parts()->count())->toBe(1)->and(Expense::first()->status)->toBe('Active')->and(($this->balance)('main'))->toBe(-60.0);
});

test('locked jobs cannot add stock or outer products and outer product expenses must be cancelled from the job', function () {
    $this->post(route('jobs.parts.outer', $this->job), $this->outerData)->assertSessionHasNoErrors();
    $this->post(route('finance-module.cancel', ['module' => 'expense', 'id' => Expense::first()->id]), ['reason' => 'Separate cancel attempted'])->assertUnprocessable();
    $this->job->update(['status' => 'Completed']);
    $this->post(route('jobs.parts.outer', $this->job), $this->outerData)->assertSessionHasErrors('parts');
    expect($this->job->parts()->count())->toBe(1)->and(Expense::count())->toBe(1);
});

test('new product popup saves real stock and returns to the job with it selected', function () {
    $category = ProductCategory::create(['name' => 'Parts']);
    $this->post(route('jobs.products.store', $this->job), ['name' => 'New Seal', 'code' => 'NEW-SEAL', 'category_id' => $category->id, 'brand' => 'ABC', 'unit' => 'Piece', 'cost_price' => 20, 'selling_price' => 40, 'minimum_stock' => 0, 'opening_quantity' => 3, 'job_store_id' => $this->store->id])->assertSessionHasNoErrors();
    $product = Product::first();
    expect((float) StoreStock::first()->quantity)->toBe(3.0)->and(Expense::count())->toBe(0)->and($this->job->parts()->count())->toBe(0);
    $this->get(route('jobs.show', ['job' => $this->job, 'product' => $product->id, 'store' => $this->store->id]))->assertOk()->assertSee('value="'.$product->id.'"', false);
});

test('outer purchase requires expense permission and ignores inactive accounts', function () {
    Account::systemAccount('main')->update(['active' => false]);
    $this->post(route('jobs.parts.outer', $this->job), $this->outerData)->assertSessionHasErrors('account_id');
    expect($this->job->parts()->count())->toBe(0)->and(Expense::count())->toBe(0);
    $role = Role::create(['name' => 'Job Parts Only', 'permissions' => ['job_card.add_parts', 'job_card.view']]);
    $this->actingAs(User::factory()->create(['role_id' => $role->id]))->withOpenCashier();
    $this->post(route('jobs.parts.outer', $this->job), $this->outerData)->assertForbidden();
    $this->post(route('jobs.products.store', $this->job), [])->assertForbidden();
});

test('outer expenses can be found by bike and job references and cannot overflow the cost ledger', function () {
    $this->post(route('jobs.parts.outer', $this->job), $this->outerData)->assertSessionHasNoErrors();
    foreach (['OUT-2255', $this->job->number] as $reference) {
        $this->get(route('expenses.index', ['q' => $reference, 'category' => 'Outer Products']))->assertOk()->assertSeeText("Outer O'Ring (Genuine)")->assertSeeText('View / Edit Job Part');
    }
    $this->post(route('jobs.parts.outer', $this->job), array_replace($this->outerData, ['cost_price' => 99999999, 'unit_price' => 1, 'quantity' => 999999]))->assertSessionHasErrors('cost_price');
    expect($this->job->parts()->count())->toBe(1)->and(Expense::count())->toBe(1)->and(($this->balance)('main'))->toBe(-60.0);
});

test('each stock part can save edit preserve and clear its own line discount', function () {
    $product = Product::create(['code' => 'LINE-DISC', 'name' => 'Discount Brake Pad', 'cost_price' => 40, 'selling_price' => 100, 'active' => true]);
    StoreStock::create(['store_id' => $this->store->id, 'product_id' => $product->id, 'quantity' => 5]);
    $this->post(route('jobs.parts.store', $this->job), ['product_id' => $product->id, 'store_id' => $this->store->id, 'quantity' => 2, 'unit_price' => 100, 'discount' => 10])->assertSessionHasNoErrors();
    $part = $this->job->parts()->sole();
    $edit = ['quantity' => 2, 'unit_price' => 100, 'discount' => 50];
    $this->post(route('jobs.parts.update', ['job' => $this->job, 'part' => $part]), $edit)->assertSessionHasNoErrors();
    expect((float) $part->fresh()->discount)->toBe(50.0)->and((float) $part->fresh()->total)->toBe(150.0)
        ->and((float) $part->fresh()->profit)->toBe(70.0)->and((float) $this->job->fresh()->parts_total)->toBe(150.0)
        ->and((float) StoreStock::first()->quantity)->toBe(3.0);
    $this->get(route('jobs.show', $this->job))->assertOk()->assertSee('id="pe-discount"', false)->assertSeeText('Line total after discount:')->assertSeeText('Discount (LKR)');
    $this->post(route('jobs.parts.update', ['job' => $this->job, 'part' => $part]), ['quantity' => 2, 'unit_price' => 100])->assertSessionHasNoErrors();
    expect((float) $part->fresh()->discount)->toBe(50.0)->and((float) $part->fresh()->total)->toBe(150.0);
    $this->post(route('jobs.parts.update', ['job' => $this->job, 'part' => $part]), array_replace($edit, ['discount' => 0]))->assertSessionHasNoErrors();
    expect((float) $part->fresh()->discount)->toBe(0.0)->and((float) $part->fresh()->total)->toBe(200.0);
});

test('line discounts flow to an invoice once and stay separate from the overall job discount', function () {
    $this->post(route('jobs.parts.outer', $this->job), $this->outerData + ['quantity' => 2])->assertSessionHasNoErrors();
    $part = $this->job->parts()->sole();
    $this->post(route('jobs.parts.update', ['job' => $this->job, 'part' => $part]), ['quantity' => 2, 'unit_price' => 100, 'discount' => 20])->assertSessionHasNoErrors();
    $this->put(route('jobs.update', $this->job), ['status' => 'Received', 'discount_percentage' => 10, 'fixed_discount' => 0])->assertSessionHasNoErrors();
    $this->post(route('jobs.invoice', $this->job), ['paid_amount' => 0, 'payment_method' => 'Credit/Due'])->assertSessionHasNoErrors();
    $invoice = $this->job->fresh()->invoice;
    expect((float) $invoice->parts_amount)->toBe(180.0)->and((float) $invoice->discount)->toBe(18.0)->and((float) $invoice->total)->toBe(162.0);
    $this->get(route('invoices.public', $invoice->public_token))->assertOk()->assertSeeText('Line discount: Rs. 20.00');
    $this->get(route('sales.invoices.show', $invoice))->assertOk()->assertSeeText('Line discount: Rs. 20.00');
    $this->get(route('invoices.public', ['token' => $invoice->public_token, 'format' => '80mm']))->assertOk()->assertSeeText('Line discount: Rs. 20.00');
    $this->post(route('jobs.unlock', $this->job), ['status' => 'In Progress', 'reason' => 'Correct line discount'])->assertSessionHasNoErrors();
    $this->post(route('jobs.parts.update', ['job' => $this->job, 'part' => $part]), ['quantity' => 2, 'unit_price' => 100, 'discount' => 30])->assertSessionHasNoErrors();
    expect((float) $invoice->fresh()->parts_amount)->toBe(170.0)->and((float) $invoice->fresh()->discount)->toBe(17.0)->and((float) $invoice->fresh()->total)->toBe(153.0);
});

test('discounting an outer product changes profit without reposting its purchase expense', function () {
    $this->post(route('jobs.parts.outer', $this->job), $this->outerData)->assertSessionHasNoErrors();
    $part = $this->job->parts()->sole();
    $ledgerCount = AccountTransaction::count();
    $this->post(route('jobs.parts.update', ['job' => $this->job, 'part' => $part]), ['quantity' => 1, 'unit_price' => 100, 'discount' => 15])->assertSessionHasNoErrors();
    expect((float) $part->fresh()->discount)->toBe(15.0)->and((float) $part->fresh()->profit)->toBe(25.0)
        ->and((float) Expense::first()->amount)->toBe(60.0)->and(AccountTransaction::count())->toBe($ledgerCount);
});

test('invalid per-product discounts roll back totals stock and expenses', function ($discount) {
    $this->post(route('jobs.parts.outer', $this->job), $this->outerData)->assertSessionHasNoErrors();
    $part = $this->job->parts()->sole();
    $ledgerCount = AccountTransaction::count();
    $this->post(route('jobs.parts.update', ['job' => $this->job, 'part' => $part]), ['quantity' => 2, 'unit_price' => 100, 'discount' => $discount])->assertSessionHasErrors('discount');
    expect((float) $part->fresh()->quantity)->toBe(1.0)->and((float) $part->fresh()->total)->toBe(100.0)->and((float) $part->fresh()->discount)->toBe(0.0)
        ->and(AccountTransaction::count())->toBe($ledgerCount)->and((float) Expense::first()->amount)->toBe(60.0);
})->with([-1, 201, 1.234, 'invalid', null]);

test('discount edits cannot bypass billed-job locks or reduce an invoice below money already received', function () {
    $this->post(route('jobs.parts.outer', $this->job), $this->outerData)->assertSessionHasNoErrors();
    $part = $this->job->parts()->sole();
    $this->post(route('jobs.invoice', $this->job), ['paid_amount' => 100, 'payment_method' => 'Cash'])->assertSessionHasNoErrors();
    $edit = ['quantity' => 1, 'unit_price' => 100, 'discount' => 10];
    $this->post(route('jobs.parts.update', ['job' => $this->job, 'part' => $part]), $edit)->assertSessionHasErrors('parts');
    $this->post(route('jobs.unlock', $this->job), ['status' => 'In Progress', 'reason' => 'Review part discount'])->assertSessionHasNoErrors();
    $this->post(route('jobs.parts.update', ['job' => $this->job, 'part' => $part]), $edit)->assertSessionHasErrors('total');
    expect((float) $part->fresh()->discount)->toBe(0.0)->and((float) $this->job->fresh()->invoice->total)->toBe(100.0);
});
