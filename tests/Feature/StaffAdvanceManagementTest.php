<?php

use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\Technician;
use App\Models\TechnicianAdvance;
use App\Models\User;
use App\Services\SalaryCalculationService;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    $this->staff = Technician::create(['name' => 'Advance Staff', 'phone' => '0700000001', 'role' => 'Technician', 'active' => true]);
    $this->otherStaff = Technician::create(['name' => 'Office Staff', 'phone' => '0700000002', 'role' => 'Staff', 'active' => true]);
    Account::ensureSystemAccounts();
    $this->main = Account::systemAccount('main');
    $this->spare = Account::systemAccount('spare_parts');
    $this->bank = Account::systemAccount('bank');
    foreach ([$this->main, $this->spare, $this->bank] as $account) {
        $account->update(['opening_balance' => 100000]);
    }
    $this->advanceData = [
        'technician_id' => $this->staff->id, 'account_id' => $this->main->id,
        'payment_date' => '2026-10-15', 'amount' => 5000, 'payment_method' => 'Cash',
        'deduct_month' => '2026-10', 'note' => 'Original advance',
    ];
    $this->post(route('technicians.advances.store'), $this->advanceData)->assertSessionHasNoErrors();
    $this->advance = TechnicianAdvance::firstOrFail();
});

it('shows staff labels and fills the edit form with the original payment', function () {
    $this->get(route('technicians.advances'))->assertOk()
        ->assertSeeText('Staff / Payroll')->assertSeeText('Select staff')
        ->assertSee('data-edit-advance=', false)->assertSee('aria-controls="edit-advance-dialog"', false)
        ->assertSeeText('Office Staff')->assertSee('Edit advance '.$this->advance->number)
        ->assertSee('Delete advance '.$this->advance->number)
        ->assertDontSeeText('Select technician');
    $this->get(route('technicians.advances.edit', $this->advance))->assertOk()
        ->assertSee('data-open-on-load', false)
        ->assertSeeText('Edit Advance Payment')->assertSeeText('Save Changes')
        ->assertSee('value="2026-10-15"', false)->assertSee('value="2026-10"', false)
        ->assertSee('value="5000.00"', false)->assertSee('value="Original advance"', false)
        ->assertSee('value="'.$this->staff->id.'" selected', false)
        ->assertSee('value="'.$this->main->id.'" selected', false);
});

it('reopens the edit popup with submitted values after validation fails', function () {
    $this->from(route('technicians.advances'))->put(route('technicians.advances.update', $this->advance), array_replace($this->advanceData, [
        '_editing_advance_id' => $this->advance->id, 'technician_id' => $this->otherStaff->id,
        'amount' => 0, 'note' => 'Retain this correction',
    ]))->assertRedirect(route('technicians.advances'))->assertSessionHasErrors('amount');

    $response = $this->get(route('technicians.advances'))->assertOk()->assertSee('data-open-on-load', false);
    $document = new DOMDocument;
    @$document->loadHTML($response->getContent());
    $xpath = new DOMXPath($document);
    expect($xpath->query('//dialog[@id="edit-advance-dialog"]//select[@name="technician_id"]/option[@selected]')->item(0)->getAttribute('value'))
        ->toBe((string) $this->otherStaff->id)
        ->and($xpath->query('//dialog[@id="edit-advance-dialog"]//input[@name="note"]')->item(0)->getAttribute('value'))->toBe('Retain this correction')
        ->and($xpath->query('//dialog[@id="edit-advance-dialog"]//input[@name="amount"]')->item(0)->getAttribute('value'))->toBe('0')
        ->and($xpath->query('//form[@id="advance-form"]//input[@name="note"]')->item(0)->getAttribute('value'))->toBe('');
});

it('changes the staff member without adding duplicate account entries', function () {
    $number = $this->advance->number;
    $this->put(route('technicians.advances.update', $this->advance), array_replace($this->advanceData, [
        'technician_id' => $this->otherStaff->id, 'deduct_month' => '2026-11', 'note' => 'Correct staff',
    ]))->assertRedirect(route('technicians.advances'))->assertSessionHasNoErrors();
    expect($this->advance->fresh()->technician_id)->toBe($this->otherStaff->id)
        ->and($this->advance->fresh()->number)->toBe($number)
        ->and($this->advance->fresh()->deduct_month->format('Y-m'))->toBe('2026-11')
        ->and($this->staff->advances()->count())->toBe(0)
        ->and($this->otherStaff->advances()->count())->toBe(1)
        ->and(AccountTransaction::count())->toBe(1)
        ->and($this->main->fresh()->balance)->toBe(95000.0);
    expect(AuditLog::where('event', 'Staff Advance Updated')->firstOrFail()->old_values['technician_id'])->toBe($this->staff->id);
});

it('reverses prior postings through repeated payment edits and deletion', function () {
    $update = array_replace($this->advanceData, [
        'technician_id' => $this->otherStaff->id, 'account_id' => $this->spare->id,
        'amount' => 7000, 'payment_date' => '2026-10-14', 'payment_method' => 'Bank Transfer',
    ]);
    $this->put(route('technicians.advances.update', $this->advance), $update)->assertSessionHasNoErrors();
    expect($this->main->fresh()->balance)->toBe(100000.0)
        ->and($this->spare->fresh()->balance)->toBe(93000.0)
        ->and($this->bank->fresh()->balance)->toBe(93000.0);
    $this->assertDatabaseHas('account_transactions', [
        'account_id' => $this->spare->id, 'reference_id' => $this->advance->id,
        'transaction_date' => '2026-10-14 00:00:00', 'debit' => 7000, 'status' => 'active',
    ]);

    $update = array_replace($update, ['account_id' => $this->main->id, 'amount' => 6000, 'payment_method' => 'Cash']);
    $this->put(route('technicians.advances.update', $this->advance), $update)->assertSessionHasNoErrors();
    expect($this->main->fresh()->balance)->toBe(94000.0)
        ->and($this->spare->fresh()->balance)->toBe(100000.0)
        ->and($this->bank->fresh()->balance)->toBe(100000.0);

    $this->delete(route('technicians.advances.destroy', $this->advance))
        ->assertRedirect(route('technicians.advances'))->assertSessionHasNoErrors();
    expect($this->advance->fresh()->status)->toBe('Cancelled')
        ->and($this->advance->fresh()->cancelled_at)->not->toBeNull()
        ->and($this->main->fresh()->balance)->toBe(100000.0)
        ->and($this->spare->fresh()->balance)->toBe(100000.0)
        ->and($this->bank->fresh()->balance)->toBe(100000.0);
    $this->get(route('technicians.advances'))->assertOk()->assertDontSeeText($this->advance->number)
        ->assertViewHas('stats', fn ($stats) => (float) $stats['pending'] === 0.0);
    $this->assertDatabaseHas('audit_logs', ['event' => 'Staff Advance Deleted', 'auditable_id' => $this->advance->id]);

    $count = AccountTransaction::count();
    $this->deleteJson(route('technicians.advances.destroy', $this->advance))->assertUnprocessable()->assertJsonValidationErrors('advance');
    $this->putJson(route('technicians.advances.update', $this->advance), $update)->assertUnprocessable()->assertJsonValidationErrors('advance');
    expect(AccountTransaction::count())->toBe($count);
});

it('posts directly to the selected bank account only once', function () {
    $this->put(route('technicians.advances.update', $this->advance), array_replace($this->advanceData, [
        'account_id' => $this->bank->id, 'payment_method' => 'Bank Transfer',
    ]))->assertSessionHasNoErrors();
    expect($this->main->fresh()->balance)->toBe(100000.0)
        ->and($this->bank->fresh()->balance)->toBe(95000.0);
    $this->delete(route('technicians.advances.destroy', $this->advance))->assertSessionHasNoErrors();
    expect($this->bank->fresh()->balance)->toBe(100000.0);
});

it('records a new advance for non-technician staff using the selected account', function () {
    $this->post(route('technicians.advances.store'), array_replace($this->advanceData, [
        'technician_id' => $this->otherStaff->id, 'account_id' => $this->spare->id, 'amount' => 2500,
    ]))->assertRedirect(route('technicians.advances'))->assertSessionHasNoErrors();
    expect($this->otherStaff->advances()->count())->toBe(1)
        ->and($this->main->fresh()->balance)->toBe(95000.0)
        ->and($this->spare->fresh()->balance)->toBe(97500.0);
});

it('moves the salary deduction to the chosen staff and month and removes it on deletion', function () {
    $calculator = app(SalaryCalculationService::class);
    expect($calculator->getAdvanceSummary($this->staff->id, '2026-10')['total_amount'])->toBe(5000.0);
    $this->put(route('technicians.advances.update', $this->advance), array_replace($this->advanceData, [
        'technician_id' => $this->otherStaff->id, 'deduct_month' => '2026-11', 'amount' => 6500,
    ]))->assertSessionHasNoErrors();
    expect($calculator->getAdvanceSummary($this->staff->id, '2026-10')['total_amount'])->toBe(0.0)
        ->and($calculator->getAdvanceSummary($this->otherStaff->id, '2026-10')['total_amount'])->toBe(0.0)
        ->and($calculator->getAdvanceSummary($this->otherStaff->id, '2026-11')['total_amount'])->toBe(6500.0);
    $this->delete(route('technicians.advances.destroy', $this->advance))->assertSessionHasNoErrors();
    expect($calculator->getAdvanceSummary($this->otherStaff->id, '2026-11')['total_amount'])->toBe(0.0);
});

it('prevents editing and deleting deducted advances on the server and in the list', function () {
    $this->advance->update(['status' => 'Deducted']);
    $this->get(route('technicians.advances'))->assertOk()
        ->assertDontSee('Edit advance '.$this->advance->number)
        ->assertDontSee('Delete advance '.$this->advance->number)
        ->assertSeeText('Reopen salary sheet to edit or delete');
    $this->getJson(route('technicians.advances.edit', $this->advance))->assertUnprocessable();
    $this->putJson(route('technicians.advances.update', $this->advance), $this->advanceData)
        ->assertUnprocessable()->assertJsonValidationErrors('advance');
    $this->deleteJson(route('technicians.advances.destroy', $this->advance))
        ->assertUnprocessable()->assertJsonValidationErrors('advance');
    expect($this->advance->fresh()->status)->toBe('Deducted')
        ->and($this->main->fresh()->balance)->toBe(95000.0)
        ->and(AccountTransaction::count())->toBe(1);
});

it('preserves existing inactive staff in the edit form', function () {
    $this->staff->update(['active' => false]);
    $this->get(route('technicians.advances.edit', $this->advance))->assertOk()
        ->assertSee('value="'.$this->staff->id.'" selected', false);
    $this->put(route('technicians.advances.update', $this->advance), array_replace($this->advanceData, ['note' => 'Corrected note']))
        ->assertSessionHasNoErrors();
    expect($this->advance->fresh()->note)->toBe('Corrected note');
});

it('rejects invalid payment changes without touching the payment or ledger', function ($changes, $field) {
    $this->otherStaff->update(['active' => false]);
    $this->spare->update(['active' => false]);
    $this->putJson(route('technicians.advances.update', $this->advance), array_replace($this->advanceData, $changes($this)))
        ->assertUnprocessable()->assertJsonValidationErrors($field);
    expect($this->advance->fresh()->technician_id)->toBe($this->staff->id)
        ->and((float) $this->advance->fresh()->amount)->toBe(5000.0)
        ->and($this->main->fresh()->balance)->toBe(95000.0)
        ->and(AccountTransaction::count())->toBe(1);
})->with([
    'zero amount' => [fn ($test) => ['amount' => 0], 'amount'],
    'fraction below currency precision' => [fn ($test) => ['amount' => 0.001], 'amount'],
    'unknown staff' => [fn ($test) => ['technician_id' => 999999], 'technician_id'],
    'inactive replacement staff' => [fn ($test) => ['technician_id' => $test->otherStaff->id], 'technician_id'],
    'inactive replacement account' => [fn ($test) => ['account_id' => $test->spare->id], 'account_id'],
    'invalid month' => [fn ($test) => ['deduct_month' => '2026-13'], 'deduct_month'],
]);

it('rolls back ledger reversals if replacement posting fails', function () {
    $this->bank->update(['active' => false]);
    $this->putJson(route('technicians.advances.update', $this->advance), array_replace($this->advanceData, [
        'amount' => 7000, 'payment_method' => 'Bank Transfer',
    ]))->assertUnprocessable()->assertJsonValidationErrors('account_id');
    expect($this->main->fresh()->balance)->toBe(95000.0)
        ->and((float) $this->advance->fresh()->amount)->toBe(5000.0)
        ->and($this->advance->fresh()->payment_method)->toBe('Cash')
        ->and(AccountTransaction::count())->toBe(1)
        ->and(AccountTransaction::first()->status)->toBe('active');
});

it('requires advance permission for editing and deletion', function () {
    $role = Role::create(['name' => 'Staff Viewer', 'permissions' => ['technician.view']]);
    $this->actingAs(User::factory()->create(['role_id' => $role->id]));
    $this->get(route('technicians.advances'))->assertOk()
        ->assertDontSee('Edit advance '.$this->advance->number)
        ->assertDontSee('Delete advance '.$this->advance->number);
    $this->get(route('technicians.advances.edit', $this->advance))->assertForbidden();
    $this->put(route('technicians.advances.update', $this->advance), $this->advanceData)->assertForbidden();
    $this->delete(route('technicians.advances.destroy', $this->advance))->assertForbidden();
    expect($this->main->fresh()->balance)->toBe(95000.0);
});
