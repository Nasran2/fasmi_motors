<?php

use App\Models\Account;
use App\Models\Bike;
use App\Models\BikeOwnershipChange;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\JobCard;
use App\Models\User;

test('all customer and bike module pages render their complete interfaces', function () {
    $user = User::factory()->create();
    Account::create(['code' => 'TEST-CASH', 'name' => 'Test Cash', 'type' => 'main', 'active' => true]);

    foreach (['customers.index', 'customers.create', 'customers.bikes.index', 'customers.bikes.create', 'customers.dues', 'customers.service-history', 'customers.ownership'] as $route) {
        $this->actingAs($user)->get(route($route))->assertOk()->assertDontSeeText('Page available')->assertDontSeeText('No records to display yet');
    }
});

test('customer quick add creates a real customer and linked bike in main register', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('customers.store'), [
        'name' => 'Kasun Customer', 'phone' => '0771110000', 'customer_type' => 'Regular Customer', 'active' => 1,
        'bike_number' => 'WP TEST 7788', 'bike_model' => 'Yamaha FZ', 'bike_brand' => 'Yamaha', 'current_km' => 24000,
    ])->assertRedirect();

    $customer = Customer::where('phone', '0771110000')->firstOrFail();
    expect($customer->bikes()->where('number', 'WP TEST 7788')->exists())->toBeTrue();
    $this->actingAs($user)->get(route('customers.index', ['q' => 'WP TEST 7788']))->assertOk()->assertSeeText('Kasun Customer');
});

test('due payment and ownership change update balances and preserve bike history', function () {
    $user = User::factory()->create();
    $oldOwner = Customer::create(['name' => 'Old Owner', 'phone' => '0772000000']);
    $newOwner = Customer::create(['name' => 'New Owner', 'phone' => '0773000000']);
    $bike = Bike::create(['customer_id' => $oldOwner->id, 'number' => 'WP OWNER 1', 'model' => 'Honda Dio']);
    $intake = $bike->intakes()->create(['intake_number' => 'IN-OWNER-1', 'complaint' => 'Ownership history service', 'created_by' => $user->id]);
    $job = JobCard::create(['number' => 'JOB-OWNER-1', 'bike_intake_id' => $intake->id, 'status' => 'Billed', 'priority' => 'Normal', 'created_by' => $user->id]);
    $invoice = Invoice::create(['number' => 'INV-OWNER-1', 'job_card_id' => $job->id, 'customer_id' => $oldOwner->id, 'subtotal' => 5000, 'total' => 5000, 'paid_amount' => 2000, 'due_amount' => 3000, 'status' => 'Partially Paid', 'due_date' => today()->addDays(10), 'public_token' => str_repeat('o', 48), 'created_by' => $user->id]);
    $account = Account::create(['code' => 'DUE-CASH', 'name' => 'Due Cash', 'type' => 'main', 'active' => true]);

    $this->actingAs($user)->post(route('customers.dues.payment', $invoice), ['amount' => 1000, 'method' => 'Cash', 'account_id' => $account->id, 'note' => 'Counter collection'])->assertSessionHasNoErrors();
    expect((float) $invoice->fresh()->due_amount)->toBe(2000.0);

    $this->actingAs($user)->post(route('customers.ownership.store'), ['bike_id' => $bike->id, 'new_customer_id' => $newOwner->id, 'changed_at' => today()->toDateString(), 'due_handling' => 'transfer_new', 'reason' => 'Bike sold'])->assertSessionHasNoErrors();
    expect($bike->fresh()->customer_id)->toBe($newOwner->id)
        ->and($invoice->fresh()->customer_id)->toBe($newOwner->id)
        ->and(BikeOwnershipChange::where('bike_id', $bike->id)->exists())->toBeTrue()
        ->and($bike->intakes()->where('id', $intake->id)->exists())->toBeTrue();
});
