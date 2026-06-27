<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Http\Request;

class SettingController extends Controller
{
    public function index()
    {
        return view('system-settings.index', ['settings' => Setting::pluck('value', 'key')]);
    }

    public function update(Request $r)
    {
        $v = $r->validate(['business_name' => 'required|max:150', 'business_phone' => 'required|max:50', 'business_address' => 'nullable|max:500', 'currency' => 'required|max:10', 'default_commission' => 'required|numeric|min:0|max:100', 'epf_employee' => 'required|numeric|min:0|max:100', 'epf_company' => 'required|numeric|min:0|max:100', 'etf_company' => 'required|numeric|min:0|max:100', 'default_reminder_days' => 'required|integer|min:1', 'negative_stock' => 'nullable']);
        foreach ($v as $k => $value) {
            Setting::updateOrCreate(['key' => $k], ['group' => str_contains($k, 'epf') || str_contains($k, 'commission') ? 'technician' : 'general', 'value' => $value ?? '0']);
        }

return back()->with('success', 'Settings saved.');
    }
}
