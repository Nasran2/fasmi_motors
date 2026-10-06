<?php

use App\Models\Account;
use App\Models\Cheque;
use App\Models\User;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 6)->startOfDay());
    $this->actingAs(User::factory()->create());
    Account::ensureSystemAccounts();
    $this->details = ['cheque_type' => 'Received', 'party_name' => 'Management Customer', 'related_reference' => 'MAN-REF', 'cheque_number' => 'MAN-001', 'bank_name' => 'Management Bank', 'branch' => 'Colombo', 'cheque_date' => '2026-10-06', 'amount' => 500, 'bank_account_id' => Account::systemAccount('bank')->id, 'note' => 'Management note'];
});

test('empty cheque review is hidden on both dashboards and management menu is separate', function () {
    $this->get(route('dashboard'))->assertOk()->assertDontSeeText('Cheques to Review')->assertSeeText('Cheque Management')->assertSee('data-title="cheque management"', false);
    $this->withSession(['shop_mode' => 'helmet'])->get(route('dashboard'))->assertOk()->assertDontSeeText('Cheques to Review')->assertSeeText('Cheque Management');
});

test('cheque management defaults to all dates and has dedicated filtered lists and reports', function () {
    $this->post(route('accounts.cheques.store'), $this->details + ['status' => 'Pending'])->assertSessionHasNoErrors();
    $pending = Cheque::first();
    $pending->update(['cheque_date' => '2026-11-20']);
    $this->post(route('accounts.cheques.store'), array_replace($this->details, ['cheque_number' => 'PASSED-002', 'status' => 'Cleared', 'realized_date' => '2026-10-06']))->assertSessionHasNoErrors();
    $this->post(route('accounts.cheques.store'), array_replace($this->details, ['cheque_number' => 'RETURNED-003', 'status' => 'Pending']))->assertSessionHasNoErrors();
    $returned = Cheque::where('cheque_number', 'RETURNED-003')->first();
    $this->patch(route('accounts.cheques.status', $returned), ['status' => 'Returned', 'reason' => 'Bank refused payment'])->assertSessionHasNoErrors();
    $this->get(route('accounts.cheques.index'))->assertOk()->assertSeeText('MAN-001')->assertSeeText('PASSED-002')->assertSeeText('RETURNED-003');
    $this->get(route('accounts.cheques.pending'))->assertOk()->assertSeeText('MAN-001')->assertDontSeeText('PASSED-002')->assertDontSeeText('RETURNED-003');
    $this->get(route('accounts.cheques.passed'))->assertOk()->assertSeeText('PASSED-002')->assertDontSeeText('MAN-001');
    $this->get(route('accounts.cheques.returned'))->assertOk()->assertSeeText('RETURNED-003')->assertDontSeeText('PASSED-002');
    $this->get(route('accounts.cheques.reports', ['date_range' => 'custom', 'start_date' => '2026-11-01', 'end_date' => '2026-11-30']))->assertOk()->assertSeeText('MAN-001')->assertDontSeeText('PASSED-002');
    $export = $this->get(route('accounts.cheques.export', ['status' => 'Cleared']))->assertOk()->assertDownload('cheque-report-2026-10-06.csv');
    expect($export->streamedContent())->toContain('PASSED-002')->not->toContain('MAN-001')->not->toContain('RETURNED-003');
    expect($this->get(route('accounts.cheques.export', ['status' => 'Returned']))->streamedContent())->toContain('RETURNED-003');
});

test('pending standalone cheques can be edited and passed cheques retain financial details', function () {
    $this->get(route('accounts.cheques.create'))->assertOk()->assertSeeText('Add Cheque');
    $this->post(route('accounts.cheques.store'), $this->details + ['status' => 'Pending'])->assertSessionHasNoErrors();
    $cheque = Cheque::first();
    $this->get(route('accounts.cheques.edit', $cheque))->assertOk();
    $this->put(route('accounts.cheques.update', $cheque), array_replace($this->details, ['amount' => 750, 'branch' => 'Kandy']))->assertSessionHasNoErrors();
    expect((float) $cheque->fresh()->amount)->toBe(750.0)->and($cheque->fresh()->branch)->toBe('Kandy');
    $this->patch(route('accounts.cheques.status', $cheque), ['status' => 'Cleared', 'realized_date' => '2026-10-06'])->assertSessionHasNoErrors();
    expect((float) Account::systemAccount('bank')->balance)->toBe(750.0);
    $this->put(route('accounts.cheques.update', $cheque), $this->details)->assertSessionHasErrors('amount');
    $this->put(route('accounts.cheques.update', $cheque), array_replace($this->details, ['amount' => 750, 'bank_name' => 'Corrected bank']))->assertSessionHasNoErrors();
    $this->get(route('accounts.cheques.show', $cheque))->assertOk()->assertSeeText('Corrected bank')->assertSeeText('Cheque History')->assertSeeText('Kandy');
    $this->delete(route('accounts.cheques.destroy', $cheque), ['reason' => 'Duplicate instrument recorded'])->assertSessionHasNoErrors();
    expect(Cheque::count())->toBe(0)->and((float) Account::systemAccount('bank')->balance)->toBe(0.0);
    $this->get(route('accounts.cheques.deleted'))->assertOk()->assertSeeText('MAN-001');
    $this->get(route('accounts.cheques.show', $cheque))->assertOk()->assertSeeText('Deleted')->assertSeeText('Duplicate instrument recorded');
    $this->get(route('accounts.cheques.edit', $cheque))->assertNotFound();
    expect($this->get(route('accounts.cheques.export', ['status' => 'Deleted']))->streamedContent())->toContain('MAN-001')->toContain('Deleted');
});

test('cheque records edits deletes and exports are scoped to the active shop', function () {
    $this->post(route('accounts.cheques.store'), $this->details + ['status' => 'Pending'])->assertSessionHasNoErrors();
    $cheque = Cheque::first();
    $this->withSession(['shop_mode' => 'helmet'])->get(route('accounts.cheques.index'))->assertOk()->assertDontSeeText('MAN-001');
    $this->get(route('accounts.cheques.show', $cheque))->assertNotFound();
    $this->put(route('accounts.cheques.update', $cheque), $this->details)->assertNotFound();
    $this->delete(route('accounts.cheques.destroy', $cheque), ['reason' => 'Attempt across shops'])->assertNotFound();
    expect($this->get(route('accounts.cheques.export'))->streamedContent())->toContain('Cheque number')->not->toContain('MAN-001');
    $this->post(route('accounts.cheques.store'), $this->details + ['status' => 'Pending'])->assertSessionHasErrors('bank_account_id');
});

test('future passing and missing deletion reasons are rejected', function () {
    $this->post(route('accounts.cheques.store'), $this->details + ['status' => 'Pending'])->assertSessionHasNoErrors();
    $cheque = Cheque::first();
    $this->patch(route('accounts.cheques.status', $cheque), ['status' => 'Cleared', 'realized_date' => '2026-10-07'])->assertSessionHasErrors('realized_date');
    $this->delete(route('accounts.cheques.destroy', $cheque), ['reason' => ''])->assertSessionHasErrors('reason');
    expect($cheque->fresh()->status)->toBe('Pending');
});

test('print and CSV reports contain all filtered rows beyond the first register page', function () {
    foreach (range(1, 26) as $number) {
        Cheque::create(array_replace($this->details, ['cheque_number' => 'PAGE-'.str_pad((string) $number, 2, '0', STR_PAD_LEFT), 'status' => 'Pending', 'created_by' => auth()->id()]));
    }
    $this->get(route('accounts.cheques.print'))->assertOk()->assertSeeText('PAGE-01')->assertSeeText('PAGE-26')->assertSeeText('Print / Save PDF');
    $csv = $this->get(route('accounts.cheques.export'))->streamedContent();
    expect($csv)->toContain('PAGE-01')->toContain('PAGE-26');
});

test('cheque management respects existing cheque and export permissions', function () {
    $role = \App\Models\Role::create(['name' => 'Dashboard only', 'permissions' => ['dashboard.view']]);
    $user = User::factory()->create(['role_id' => $role->id]);
    $this->actingAs($user)->get(route('dashboard'))->assertOk()->assertDontSeeText('Cheque Management');
    foreach (['index','create','reports','print','export'] as $action) {
        $this->get(route('accounts.cheques.'.$action))->assertForbidden();
    }
    $role->update(['permissions' => ['account.cheques']]);
    $this->actingAs($user->fresh())->get(route('accounts.cheques.reports'))->assertOk()->assertDontSeeText('Export CSV');
    $this->get(route('accounts.cheques.export'))->assertForbidden();
});
