<?php

use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\User;
use App\Services\AccountService;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    Account::ensureSystemAccounts();
    $customer = Customer::create(['name' => 'Opening Due Customer', 'phone' => '0776000080']);
    $this->invoice = Invoice::create([
        'number' => 'OP-MAIN-ACCOUNT', 'invoice_type' => 'Opening Due', 'customer_id' => $customer->id,
        'subtotal' => 1000, 'total' => 1000, 'due_amount' => 1000, 'status' => 'Unpaid',
        // Even legacy records with a parts value must not split opening dues.
        'parts_amount' => 750, 'public_token' => Str::random(48), 'created_by' => auth()->id(),
    ]);
});

test('opening due collections belong entirely to main for each payment method', function (string $type, string $method) {
    $this->invoice->update(['invoice_type' => $type]);
    expect(AccountTransaction::count())->toBe(0);
    $date = today()->subDay()->toDateString();
    $row = ['method' => $method, 'amount' => 300, 'account_id' => Account::systemAccount('spare_parts')->id];
    $this->post(route('customers.dues.payment', $this->invoice), ['paid_at' => $date, 'payments' => [$row]])->assertSessionHasNoErrors();
    expect((float) $this->invoice->fresh()->due_amount)->toBe(700.0)
        ->and($this->invoice->fresh()->status)->toBe('Partially Paid')
        ->and(Account::systemAccount('main')->balance)->toBe(300.0)
        ->and(Account::systemAccount('spare_parts')->balance)->toBe(0.0);
    $entry = AccountTransaction::where('account_type', 'main')->sole();
    expect($entry->category)->toBe('opening_due')->and($entry->transaction_date->toDateString())->toBe($date);
    $row['amount'] = $method === 'Cash' ? 1000 : 700;
    $this->post(route('customers.dues.payment', $this->invoice), ['payments' => [$row]])->assertSessionHasNoErrors();
    app(AccountService::class)->reallocateInvoiceReceipts($this->invoice->fresh());
    expect((float) $this->invoice->fresh()->due_amount)->toBe(0.0)
        ->and($this->invoice->fresh()->status)->toBe('Paid')
        ->and(Account::systemAccount('main')->balance)->toBe(1000.0)
        ->and(AccountTransaction::where('account_type', 'spare_parts')->count())->toBe(0)
        ->and(Account::systemAccount('bank')->balance)->toBe($method === 'Cash' ? 0.0 : 1000.0)
        ->and((float) $this->invoice->fresh()->change_amount)->toBe($method === 'Cash' ? 300.0 : 0.0);
})->with([
    ['Opening Due', 'Cash'], ['Opening Due', 'Bank Transfer'], ['Opening Due', 'Card'],
    ['Opening Balance', 'Cash'], ['Opening Balance', 'Bank Transfer'], ['Opening Balance', 'QR'],
]);

test('opening due cheques post to main only after clearing and reverse on return', function (string $type) {
    $this->invoice->update(['invoice_type' => $type]);
    $chequeDate = today()->addDays(2)->toDateString();
    $this->post(route('customers.dues.payment', $this->invoice), ['payments' => [[
        'method' => 'Cheque', 'amount' => 1000, 'account_id' => Account::systemAccount('bank')->id,
        'cheque_date' => $chequeDate, 'cheque_number' => 'OP-CHQ-100', 'bank_name' => 'Test Bank',
    ]]])->assertSessionHasNoErrors();
    $cheque = $this->invoice->payments()->sole()->cheque;
    expect(AccountTransaction::count())->toBe(0)->and($this->invoice->fresh()->status)->toBe('Hold');
    $this->patch(route('accounts.cheques.status', $cheque), ['status' => 'Cleared', 'realized_date' => today()->toDateString()])->assertSessionHasErrors('status');
    $this->travelTo(today()->addDays(2));
    foreach (range(1, 2) as $_) {
        $this->patch(route('accounts.cheques.status', $cheque), ['status' => 'Cleared', 'realized_date' => $chequeDate])->assertSessionHasNoErrors();
    }
    expect(Account::systemAccount('main')->balance)->toBe(1000.0)
        ->and(Account::systemAccount('bank')->balance)->toBe(1000.0)
        ->and(AccountTransaction::where('account_type', 'spare_parts')->count())->toBe(0)
        ->and(AccountTransaction::where('account_type', 'main')->sole()->category)->toBe('opening_due');
    $this->patch(route('accounts.cheques.status', $cheque), ['status' => 'Returned', 'reason' => 'Cheque rejected by bank'])->assertSessionHasNoErrors();
    expect(Account::systemAccount('main')->balance)->toBe(0.0)
        ->and(Account::systemAccount('bank')->balance)->toBe(0.0)
        ->and((float) $this->invoice->fresh()->due_amount)->toBe(1000.0);
})->with(['Opening Due', 'Opening Balance']);
