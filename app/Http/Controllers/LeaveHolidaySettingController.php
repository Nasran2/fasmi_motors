<?php

namespace App\Http\Controllers;

use App\Models\HolidayDate;
use App\Models\LeaveHolidaySetting;
use Carbon\Carbon;
use Illuminate\Http\Request;

class LeaveHolidaySettingController extends Controller
{
    public function index()
    {
        $setting = LeaveHolidaySetting::firstOrCreate(['key' => 'weekly_leave_days']);
        $weeklyLeaves = $setting->value ? json_decode($setting->value, true) : ['Friday'];

        $holidays = HolidayDate::orderBy('holiday_date')->get();

        return view('settings.leave-holidays', compact('weeklyLeaves', 'holidays'));
    }

    public function storeWeeklyLeaves(Request $request)
    {
        $request->validate([
            'days' => 'array',
            'days.*' => 'string|in:Monday,Tuesday,Wednesday,Thursday,Friday,Saturday,Sunday',
        ]);

        $setting = LeaveHolidaySetting::firstOrCreate(['key' => 'weekly_leave_days']);
        $setting->value = json_encode($request->input('days', []));
        $setting->save();

        return back()->with('success', 'Weekly leave days updated successfully.');
    }

    public function storeHoliday(Request $request)
    {
        $request->validate([
            'holiday_name' => 'required|string|max:255',
            'holiday_date' => 'required|date|unique:holiday_dates,holiday_date',
            'holiday_type' => 'required|string|in:Public,Poya,Custom,Company',
            'is_leave_enabled' => 'nullable|boolean',
        ]);

        HolidayDate::create([
            'holiday_name' => $request->holiday_name,
            'holiday_date' => $request->holiday_date,
            'holiday_type' => $request->holiday_type,
            'is_leave_enabled' => $request->has('is_leave_enabled'),
            'created_by' => auth()->id(),
        ]);

        return back()->with('success', 'Holiday added successfully.');
    }

    public function destroyHoliday(HolidayDate $holiday)
    {
        $holiday->delete();

        return back()->with('success', 'Holiday deleted successfully.');
    }

    public function toggleHoliday(HolidayDate $holiday)
    {
        $holiday->update(['is_leave_enabled' => ! $holiday->is_leave_enabled]);

        return back()->with('success', 'Holiday leave status updated.');
    }

    public function importSriLankaHolidays(Request $request)
    {
        $year = $request->input('year', date('Y'));
        try {
            $ics = file_get_contents('https://calendar.google.com/calendar/ical/en.lk%23holiday%40group.v.calendar.google.com/public/basic.ics');
            preg_match_all('/BEGIN:VEVENT(.*?)END:VEVENT/s', $ics, $events);

            $count = 0;
            foreach ($events[1] as $event) {
                preg_match('/DTSTART;VALUE=DATE:(\d{8})/', $event, $dateMatch);
                preg_match('/SUMMARY:(.*?)\r?\n/s', $event, $summaryMatch);

                if ($dateMatch && $summaryMatch) {
                    $dateStr = $dateMatch[1];
                    if (str_starts_with($dateStr, (string) $year)) {
                        $date = Carbon::parse($dateStr)->toDateString();
                        $name = trim(str_replace("\n ", '', $summaryMatch[1]));

                        $type = 'Public';
                        if (stripos($name, 'Poya') !== false) {
                            $type = 'Poya';
                        }

                        HolidayDate::updateOrCreate(
                            ['holiday_date' => $date],
                            [
                                'holiday_name' => $name,
                                'holiday_type' => $type,
                                'is_leave_enabled' => true,
                                'created_by' => auth()->id() ?? 1,
                            ]
                        );
                        $count++;
                    }
                }
            }

            return back()->with('success', "Successfully imported $count Sri Lankan holidays for $year.");

        } catch (\Exception $e) {
            return back()->with('error', 'Failed to import holidays: '.$e->getMessage());
        }
    }
}
