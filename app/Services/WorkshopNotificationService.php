<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\ServiceReminder;
use App\Models\Technician;
use App\Models\User;
use App\Support\RoutePermission;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class WorkshopNotificationService
{
    public function summary(User $user): array
    {
        $dismissed = DB::table('workshop_notification_dismissals')->where('user_id', $user->id)->pluck('notification_key')->flip();
        $alerts = [];
        if (RoutePermission::allows($user, 'customers.dues')) {
            $keys = Invoice::where('due_amount', '>', 0)->get(['id', 'due_amount'])
                ->map(fn (Invoice $invoice) => hash('sha256', 'payment:'.$invoice->id.':'.$invoice->due_amount))
                ->reject(fn (string $key) => $dismissed->has($key))->values()->all();
            if ($keys) {
                $alerts[] = ['title' => 'Outstanding customer payments', 'count' => count($keys), 'keys' => $keys, 'url' => route('customers.dues')];
            }
        }

        if (RoutePermission::allows($user, 'service-reminders.page', parameters: ['page' => 'upcoming'])) {
            $pending = ServiceReminder::where('status', 'Pending')->whereDate('due_date', '<=', today()->addDays(3))->get(['id', 'due_date']);
            foreach (['overdue', 'upcoming'] as $type) {
                $keys = $pending->filter(fn (ServiceReminder $reminder) => ($reminder->due_date->lt(today()) ? 'overdue' : 'upcoming') === $type)
                    ->map(fn (ServiceReminder $reminder) => hash('sha256', 'service:'.$reminder->id.':'.$reminder->due_date->toDateString().':'.$type))
                    ->reject(fn (string $key) => $dismissed->has($key))->values()->all();
                if ($keys) {
                    $alerts[] = [
                        'title' => $type === 'overdue' ? 'Overdue service reminders' : 'Services due in the next 3 days',
                        'count' => count($keys), 'keys' => $keys, 'url' => route('service-reminders.page', $type),
                    ];
                }
            }
        }

        $birthdays = [];
        if (RoutePermission::allows($user, 'technicians.history')) {
            $dates = collect(range(0, 7))->mapWithKeys(fn (int $days) => [today()->addDays($days)->format('m-d') => $days]);
            // Observe leap-day birthdays on February 28 in years without February 29.
            if (! today()->isLeapYear() && $dates->has('02-28')) {
                $dates->put('02-29', $dates->get('02-28'));
            }
            $staff = Technician::where('active', true)->whereNotNull('date_of_birth')
                ->where(function (Builder $query) use ($dates) {
                    foreach ($dates as $monthDay => $days) {
                        [$month, $day] = explode('-', $monthDay);
                        $query->orWhere(fn (Builder $date) => $date->whereMonth('date_of_birth', $month)->whereDay('date_of_birth', $day));
                    }
                })->orderBy('name')->get(['id', 'name', 'date_of_birth']);

            foreach ($staff as $person) {
                $days = $dates->get($person->date_of_birth->format('m-d'));
                $key = hash('sha256', 'birthday:'.$person->id.':'.today()->addDays($days)->toDateString());
                if ($dismissed->has($key)) {
                    continue;
                }
                $birthdays[] = [
                    'keys' => [$key],
                    'name' => $person->name, 'days' => $days,
                    'date' => today()->addDays($days)->format('d M'),
                    'url' => route('technicians.history', ['technician' => $person->id]),
                ];
            }
            $birthdays = collect($birthdays)->sortBy('days')->values()->all();
        }

        $keys = collect(array_merge($alerts, $birthdays))->pluck('keys')->flatten()->unique()->values()->all();

        return ['alerts' => $alerts, 'birthdays' => $birthdays, 'count' => count($keys), 'keys' => $keys];
    }
}
