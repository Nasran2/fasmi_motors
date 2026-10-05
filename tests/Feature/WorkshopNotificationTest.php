<?php

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Role;
use App\Models\ServiceReminder;
use App\Models\Technician;
use App\Models\User;
use App\Services\WorkshopNotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 5)->startOfDay());
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $customer = Customer::create(['name' => 'Notification Customer', 'phone' => '0770000001', 'active' => true]);
    $this->bike = $customer->bikes()->create(['number' => 'WP TEST 100', 'model' => 'Honda Dio']);
    $this->invoice = Invoice::create([
        'number' => 'NOTIFICATION-DUE', 'customer_id' => $customer->id, 'subtotal' => 1000,
        'total' => 1000, 'due_amount' => 1000, 'status' => 'Unpaid',
        'public_token' => Str::random(48), 'created_by' => $this->user->id,
    ]);
    $this->reminder = ServiceReminder::create(['bike_id' => $this->bike->id, 'due_date' => today()->subDay(), 'status' => 'Pending']);
    $this->staff = Technician::create(['name' => 'Birthday Staff', 'phone' => '0700000001', 'date_of_birth' => '1995-10-06', 'active' => true]);
});

it('clears a notification persistently for only the current user', function () {
    $notifications = app(WorkshopNotificationService::class);
    $summary = $notifications->summary($this->user);
    $keys = $summary['alerts'][0]['keys'];
    $this->from(route('dashboard'))->post(route('notifications.clear'), ['keys' => $keys])
        ->assertRedirect(route('dashboard'))->assertSessionHasNoErrors();
    expect($notifications->summary($this->user->fresh())['count'])->toBe(2);
    expect($this->invoice->fresh()->due_amount)->toEqual(1000)
        ->and($this->invoice->fresh()->status)->toBe('Unpaid')
        ->and($this->reminder->fresh()->status)->toBe('Pending');
    $this->actingAs(User::factory()->create());
    expect($notifications->summary(auth()->user())['count'])->toBe(3);
    $this->actingAs($this->user->fresh());
    $this->get(route('dashboard'))->assertOk()->assertDontSee('Outstanding customer payments')
        ->assertSee('aria-label="Notifications (2)"', false);
});

it('clears all displayed notifications and allows duplicate clears', function () {
    $notifications = app(WorkshopNotificationService::class);
    $keys = $notifications->summary($this->user)['keys'];
    $this->get(route('dashboard'))->assertOk()->assertSee('Clear all notifications')->assertSee('Clear birthday notification');
    $this->post(route('notifications.clear'), ['keys' => $keys])->assertSessionHasNoErrors();
    $this->post(route('notifications.clear'), ['keys' => $keys])->assertSessionHasNoErrors();
    expect($notifications->summary($this->user)['count'])->toBe(0)
        ->and(DB::table('workshop_notification_dismissals')->where('user_id', $this->user->id)->count())->toBe(3);
    $this->get(route('dashboard'))->assertOk()->assertSee('No notifications right now.')
        ->assertSee('aria-label="Notifications (0)"', false)->assertDontSee('Clear all notifications');
});

it('keeps new alerts that arrived after the displayed snapshot', function () {
    $notifications = app(WorkshopNotificationService::class);
    $keys = $notifications->summary($this->user)['keys'];
    Invoice::create([
        'number' => 'NEW-NOTIFICATION-DUE', 'customer_id' => $this->bike->customer_id, 'subtotal' => 500,
        'total' => 500, 'due_amount' => 500, 'status' => 'Unpaid',
        'public_token' => Str::random(48), 'created_by' => $this->user->id,
    ]);
    $this->post(route('notifications.clear'), ['keys' => $keys])->assertSessionHasNoErrors();
    $summary = $notifications->summary($this->user);
    expect($summary['count'])->toBe(1)->and($summary['alerts'][0]['count'])->toBe(1);
});

it('shows a new service alert when a cleared upcoming reminder becomes overdue', function () {
    $notifications = app(WorkshopNotificationService::class);
    $this->reminder->update(['due_date' => today()]);
    $keys = $notifications->summary($this->user)['keys'];
    $this->post(route('notifications.clear'), ['keys' => $keys])->assertSessionHasNoErrors();
    $this->travelTo(now()->addDay());
    $summary = $notifications->summary($this->user);
    expect($summary['count'])->toBe(1)->and($summary['alerts'][0]['title'])->toBe('Overdue service reminders');
});

it('keeps a birthday cleared through its date and shows it again next year', function () {
    $notifications = app(WorkshopNotificationService::class);
    $keys = $notifications->summary($this->user)['birthdays'][0]['keys'];
    $this->post(route('notifications.clear'), ['keys' => $keys])->assertSessionHasNoErrors();
    $this->travelTo(now()->setDate(2026, 10, 6));
    expect($notifications->summary($this->user)['birthdays'])->toBe([]);
    $this->travelTo(now()->setDate(2027, 10, 6));
    expect($notifications->summary($this->user)['birthdays'][0]['name'])->toBe('Birthday Staff');
});

it('cannot clear notifications the user is not permitted to view', function () {
    $notifications = app(WorkshopNotificationService::class);
    $keys = $notifications->summary($this->user)['keys'];
    $role = Role::create(['name' => 'Restricted user', 'permissions' => []]);
    $restricted = User::factory()->create(['role_id' => $role->id]);
    $this->actingAs($restricted);
    $this->post(route('notifications.clear'), ['keys' => $keys])->assertSessionHasNoErrors();
    expect(DB::table('workshop_notification_dismissals')->where('user_id', $restricted->id)->count())->toBe(0);
});

it('requires authentication and validates clear requests', function () {
    $this->post(route('notifications.clear'), ['keys' => ['invalid']])->assertSessionHasErrors('keys.0');
    $this->post(route('notifications.clear'), [])->assertSessionHasErrors('keys');
    auth()->logout();
    $this->post(route('notifications.clear'), ['keys' => [str_repeat('a', 64)]])->assertRedirect(route('login'));
});
