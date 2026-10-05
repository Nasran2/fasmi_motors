<?php

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Role;
use App\Models\ServiceReminder;
use App\Models\Technician;
use App\Models\User;
use App\Services\WorkshopNotificationService;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 5)->startOfDay());
    $this->actingAs(User::factory()->create());
});

function staffBirthdayPayload(array $attributes = []): array
{
    return array_merge([
        'name' => 'Birthday Staff', 'phone' => '0771234567', 'role' => 'staff',
        'active' => 1, 'basic_salary' => 30000, 'salary_type' => 'Monthly',
        'commission_percentage' => 0, 'commission_type' => 'Manual only',
        'epf_employee_percentage' => 8, 'epf_company_percentage' => 12,
        'etf_company_percentage' => 3,
    ], $attributes);
}

it('saves, displays, updates and clears a staff date of birth', function () {
    $this->get(route('technicians.create'))->assertOk()->assertSee('name="date_of_birth"', false);
    $this->post(route('technicians.store'), staffBirthdayPayload(['date_of_birth' => '1995-10-05']))
        ->assertRedirect()->assertSessionHasNoErrors();
    $staff = Technician::firstOrFail();
    expect($staff->date_of_birth->toDateString())->toBe('1995-10-05');
    $this->get(route('technicians.edit', $staff))->assertOk()->assertSee('value="1995-10-05"', false);
    $this->get(route('technicians.history', ['technician' => $staff->id]))->assertOk()->assertSee('DOB 05 Oct 1995');

    $this->put(route('technicians.update', $staff), staffBirthdayPayload(['date_of_birth' => '1996-02-29']))
        ->assertSessionHasNoErrors();
    expect($staff->fresh()->date_of_birth->toDateString())->toBe('1996-02-29');
    $this->put(route('technicians.update', $staff), staffBirthdayPayload(['date_of_birth' => '']))
        ->assertSessionHasNoErrors();
    expect($staff->fresh()->date_of_birth)->toBeNull();
});

it('keeps date of birth optional for existing staff workflows', function () {
    $this->post(route('technicians.store'), staffBirthdayPayload())->assertSessionHasNoErrors();
    expect(Technician::firstOrFail()->date_of_birth)->toBeNull();
});

it('rejects invalid and future birth dates', function (string $date) {
    $this->post(route('technicians.store'), staffBirthdayPayload(['date_of_birth' => $date]))
        ->assertSessionHasErrors('date_of_birth');
    expect(Technician::count())->toBe(0);
})->with(['2026-10-06', '1995-02-30', 'not-a-date']);

it('shows recurring birthdays in order and excludes inactive deleted and distant staff', function () {
    foreach ([
        ['Next Week', '1990-10-12', true], ['Today', '1991-10-05', true],
        ['Tomorrow', '1992-10-06', true], ['Inactive', '1993-10-05', false],
        ['Distant', '1994-10-13', true], ['Missing DOB', null, true], ['Deleted', '1995-10-05', true],
    ] as $index => [$name, $dob, $active]) {
        $staff = Technician::create(staffBirthdayPayload(['name' => $name, 'phone' => '070000000'.$index, 'date_of_birth' => $dob, 'active' => $active]));
        if ($name === 'Deleted') {
            $staff->delete();
        }
    }
    $summary = app(WorkshopNotificationService::class)->summary(auth()->user());
    expect(array_column($summary['birthdays'], 'name'))->toBe(['Today', 'Tomorrow', 'Next Week'])
        ->and(array_column($summary['birthdays'], 'days'))->toBe([0, 1, 7])
        ->and($summary['count'])->toBe(3);
    $this->get(route('technicians.index'))->assertOk()
        ->assertSee('Birthday today!')->assertSee('Birthday tomorrow')->assertSee('Birthday in 7 days')
        ->assertSee('aria-label="Notifications (3)"', false);
});

it('finds upcoming birthdays across the year boundary', function () {
    $this->travelTo(now()->setDate(2026, 12, 29));
    Technician::create(staffBirthdayPayload(['date_of_birth' => '1990-01-02']));
    $summary = app(WorkshopNotificationService::class)->summary(auth()->user());
    expect($summary['birthdays'][0]['days'])->toBe(4)
        ->and($summary['birthdays'][0]['date'])->toBe('02 Jan');
});

it('observes leap-day birthdays on February 28 only in non-leap years', function () {
    Technician::create(staffBirthdayPayload(['date_of_birth' => '1996-02-29']));
    $this->travelTo(now()->setDate(2027, 2, 28));
    expect(app(WorkshopNotificationService::class)->summary(auth()->user())['birthdays'][0]['days'])->toBe(0);
    $this->travelTo(now()->setDate(2028, 2, 28));
    expect(app(WorkshopNotificationService::class)->summary(auth()->user())['birthdays'][0]['days'])->toBe(1);
});

it('hides staff notifications from users without staff history access', function () {
    Technician::create(staffBirthdayPayload(['date_of_birth' => '1995-10-05']));
    $role = Role::create(['name' => 'Restricted staff', 'permissions' => ['technician.view']]);
    $this->actingAs(User::factory()->create(['role_id' => $role->id]));
    $summary = app(WorkshopNotificationService::class)->summary(auth()->user());
    expect($summary['birthdays'])->toBe([])->and($summary['count'])->toBe(0);
    $this->get(route('technicians.index'))->assertOk()->assertSee('No notifications right now.')->assertDontSee('Birthday today!');
});

it('keeps payment and service alerts visible with links and an accurate badge', function () {
    $customer = Customer::create(['name' => 'Notification Customer', 'phone' => '0770000001', 'active' => true]);
    $bike = $customer->bikes()->create(['number' => 'WP TEST 100', 'model' => 'Honda Dio']);
    Invoice::create([
        'number' => 'NOTIFICATION-DUE', 'customer_id' => $customer->id, 'subtotal' => 1000,
        'total' => 1000, 'due_amount' => 1000, 'status' => 'Unpaid',
        'public_token' => str_repeat('n', 48), 'created_by' => auth()->id(),
    ]);
    foreach ([-1, 0, 3, 4] as $days) {
        ServiceReminder::create(['bike_id' => $bike->id, 'due_date' => today()->addDays($days), 'status' => 'Pending']);
    }
    ServiceReminder::create(['bike_id' => $bike->id, 'due_date' => today(), 'status' => 'Completed']);
    Technician::create(staffBirthdayPayload(['date_of_birth' => '1995-10-05']));
    $summary = app(WorkshopNotificationService::class)->summary(auth()->user());
    expect($summary['count'])->toBe(5)
        ->and(array_column($summary['alerts'], 'count'))->toBe([1, 1, 2])
        ->and(array_column($summary['alerts'], 'url'))->toBe([
            route('customers.dues'), route('service-reminders.page', 'overdue'), route('service-reminders.page', 'upcoming'),
        ]);
    $this->get(route('technicians.index'))->assertOk()->assertSee('Outstanding customer payments')
        ->assertSee('Overdue service reminders')->assertSee('Services due in the next 3 days')
        ->assertSee('aria-label="Notifications (5)"', false);
});
