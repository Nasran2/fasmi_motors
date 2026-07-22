<?php

namespace App\Http\Controllers;

use App\Models\Bike;
use App\Models\ServiceReminder;
use App\Models\Setting;
use App\Models\SmsLog;
use App\Services\ServiceReminderService;
use Illuminate\Http\Request;

class ServiceReminderController extends Controller
{
    public function page(Request $request, string $page)
    {
        abort_unless(in_array($page, ['upcoming', 'sent', 'overdue', 'sms-logs', 'settings'], true), 404);
        if ($page === 'settings') {
            return view('service-reminders.settings', ['settings' => Setting::where('group', 'service_reminders')->pluck('value', 'key')]);
        }
        if ($page === 'sms-logs') {
            $logs = SmsLog::with(['reference', 'sender'])
                ->where('reference_type', (new ServiceReminder)->getMorphClass())
                ->latest()
                ->paginate(25);

            return view('service-reminders.logs', compact('logs'));
        }
        $query = ServiceReminder::with(['bike.customer', 'jobCard'])->when($request->q, fn ($q, $value) => $q->whereHas('bike', fn ($bike) => $bike->where('number', 'like', "%{$value}%")->orWhereHas('customer', fn ($customer) => $customer->where('name', 'like', "%{$value}%")->orWhere('phone', 'like', "%{$value}%"))));
        if ($page === 'upcoming') {
            $query->where('status', 'Pending')->whereDate('due_date', '>=', today());
        }
        if ($page === 'sent') {
            $query->whereNotNull('sent_at');
        }
        if ($page === 'overdue') {
            $query->where('status', 'Pending')->whereDate('due_date', '<', today());
        }
        $records = $query->orderBy('due_date')->paginate(20)->withQueryString();
        $bikes = Bike::with('customer')->orderBy('number')->get();

        return view('service-reminders.page', compact('page', 'records', 'bikes'));
    }

    public function store(Request $request)
    {
        $data = $request->validate(['bike_id' => 'required|exists:bikes,id', 'due_date' => 'nullable|date', 'due_km' => 'nullable|integer|min:0', 'reminder_type' => 'required|in:Date Based,KM Based,Custom', 'note' => 'nullable|max:1000']);
        ServiceReminder::create($data + ['status' => 'Pending', 'sms_status' => 'Not Sent', 'created_by' => auth()->id()]);

        return back()->with('success', 'Service reminder created.');
    }

    public function send(ServiceReminder $reminder, ServiceReminderService $service)
    {
        $result = $service->send($reminder, auth()->id());

        return $result['success']
            ? back()->with('success', 'Reminder SMS sent and logged.')
            : back()->withErrors(['sms' => $result['error'] ?? 'Check SMS settings.']);
    }

    public function complete(ServiceReminder $reminder)
    {
        $reminder->update(['status' => 'Completed', 'completed_at' => now()]);

        return back()->with('success', 'Reminder marked completed.');
    }

    public function reschedule(Request $request, ServiceReminder $reminder)
    {
        $data = $request->validate(['due_date' => 'required|date', 'note' => 'nullable|max:1000']);
        $reminder->update($data + ['status' => 'Pending']);

        return back()->with('success', 'Reminder rescheduled.');
    }

    public function saveSettings(Request $request)
    {
        foreach ($request->except('_token') as $key => $value) {
            Setting::updateOrCreate(['key' => $key], ['group' => 'service_reminders', 'value' => is_array($value) ? json_encode($value) : $value, 'type' => 'text']);
        }

        return back()->with('success', 'Reminder settings saved.');
    }
}
