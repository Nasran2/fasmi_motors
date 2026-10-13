<?php

use App\Models\Account;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\JobCard;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Str;

function customerProfileBill(Customer $customer, array $values = []): Invoice
{
    return Invoice::create(array_replace([
        'number' => 'INV-PROFILE-'.Str::upper(Str::random(8)), 'customer_id' => $customer->id,
        'invoice_type' => 'Parts Sale', 'subtotal' => 1000, 'total' => 1000, 'paid_amount' => 0,
        'due_amount' => 1000, 'status' => 'Unpaid', 'public_token' => Str::random(48), 'created_by' => auth()->id(),
    ], $values));
}

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    Account::ensureSystemAccounts();
    $this->customer = Customer::create(['name' => 'Bill History Customer', 'phone' => '0776000100']);
});

test('customer profile shows all bills opening balances and legacy job bills with correct dues', function () {
    $bike = $this->customer->bikes()->create(['number' => 'HISTORY-BIKE', 'model' => 'Yamaha']);
    $intake = $bike->intakes()->create(['intake_number' => 'IN-HISTORY', 'complaint' => 'Repair', 'created_by' => auth()->id()]);
    $job = JobCard::create(['number' => 'JOB-HISTORY', 'bike_intake_id' => $intake->id, 'status' => 'Billed', 'priority' => 'Normal', 'created_by' => auth()->id()]);
    $paid = customerProfileBill($this->customer, ['status' => 'Paid', 'paid_amount' => 1000, 'due_amount' => 0]);
    $partial = customerProfileBill($this->customer, ['status' => 'Partially Paid', 'paid_amount' => 750, 'due_amount' => 250]);
    $hold = customerProfileBill($this->customer, ['status' => 'Hold', 'hold_amount' => 1000, 'due_amount' => 0]);
    $opening = customerProfileBill($this->customer, ['invoice_type' => 'Opening Due', 'bike_id' => $bike->id]);
    $legacyOpening = customerProfileBill($this->customer, ['invoice_type' => 'Opening Balance']);
    $legacyJob = customerProfileBill($this->customer, ['invoice_type' => 'Job Card Bill', 'customer_id' => null, 'job_card_id' => $job->id]);
    $cancelled = customerProfileBill($this->customer, ['status' => 'Cancelled']);
    $quotation = customerProfileBill($this->customer, ['invoice_type' => 'Quotation', 'status' => 'Draft']);
    $otherShop = customerProfileBill($this->customer, ['shop_type' => 'helmet']);
    $otherCustomer = Customer::create(['name' => 'Another Customer', 'phone' => '0776000101']);
    $otherBill = customerProfileBill($otherCustomer, ['bike_id' => $bike->id]);

    $response = $this->get(route('customers.show', $this->customer))->assertOk()
        ->assertSeeText('Customer Bills')->assertSeeText('Opening balance')->assertSeeText('Completed')
        ->assertSeeText('Partially Paid')->assertSeeText('Cheque Hold')->assertSeeText('Cancelled')
        ->assertSee(route('sales.invoices.show', $paid), false)
        ->assertSee('data-invoice-id="'.$partial->id.'"', false)
        ->assertDontSee('data-invoice-id="'.$paid->id.'"', false)
        ->assertDontSee('data-invoice-id="'.$hold->id.'"', false)
        ->assertDontSee('data-invoice-id="'.$cancelled->id.'"', false);
    expect($response->viewData('bills')->pluck('id')->all())->toEqualCanonicalizing([$paid->id, $partial->id, $hold->id, $opening->id, $legacyOpening->id, $legacyJob->id, $cancelled->id]);
    expect((float) $response->viewData('dueAmount'))->toBe(3250.0)
        ->and((float) $response->viewData('customer')->bikes->sole()->due_amount)->toBe(2000.0);
    $response->assertDontSeeText($quotation->number)->assertDontSeeText($otherShop->number)->assertDontSeeText($otherBill->number);
});

test('bill history remains available when a customer has no bike', function () {
    $bill = customerProfileBill($this->customer, ['invoice_type' => 'Opening Due']);
    $this->get(route('customers.show', $this->customer))->assertOk()
        ->assertSeeText('No bikes registered')->assertSeeText('Customer Bills')->assertSeeText($bill->number)
        ->assertSee('data-invoice-id="'.$bill->id.'"', false);
});

test('bill history is paginated newest first without changing the full customer due', function () {
    $ids = [];
    foreach (range(1, 25) as $i) {
        $ids[] = customerProfileBill($this->customer)->id;
    }
    $response = $this->get(route('customers.show', $this->customer))->assertOk();
    $bills = $response->viewData('bills');
    expect($bills->total())->toBe(25)->and($bills->count())->toBe(20)
        ->and($bills->first()->id)->toBe($ids[24])
        ->and($bills->nextPageUrl())->toContain('bills_page=2', '#customer-bills');
    $second = $this->get(route('customers.show', ['customer' => $this->customer, 'bills_page' => 2]))->assertOk();
    expect($second->viewData('bills')->count())->toBe(5)
        ->and((float) $second->viewData('dueAmount'))->toBe(25000.0);
});

test('view-only users see customer bills without payment controls and can use enabled bill previews', function () {
    $bill = customerProfileBill($this->customer);
    $disabled = customerProfileBill($this->customer, ['link_enabled' => false]);
    $role = Role::create(['name' => 'History Viewer', 'permissions' => ['customer.view']]);
    $this->actingAs(User::factory()->create(['role_id' => $role->id]));
    $this->get(route('customers.show', $this->customer))->assertOk()->assertSeeText($bill->number)
        ->assertSee(route('invoices.public', $bill->public_token), false)
        ->assertDontSee(route('invoices.public', $disabled->public_token), false)
        ->assertDontSee('data-invoice-id="'.$bill->id.'"', false)->assertDontSee('id="due-payment-dialog"', false);
});

test('customers without bills have a clear empty history', function () {
    $this->get(route('customers.show', $this->customer))->assertOk()
        ->assertSeeText('Customer Bills')->assertSeeText('No bills recorded for this customer.');
});
