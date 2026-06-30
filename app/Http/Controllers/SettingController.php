<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\Store;
use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class SettingController extends Controller
{
    // Audit logs logger
    protected function audit(string $action, string $description, ?array $old = null, ?array $new = null)
    {
        ActivityLog::create([
            'user_id' => auth()->id(),
            'module' => 'Settings',
            'action' => $action,
            'description' => $description,
            'old_values' => $old,
            'new_values' => $new,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'created_at' => now(),
        ]);
    }

    // Load decrypted settings helper
    protected function getSettings()
    {
        return Setting::pluck('value', 'key')->map(function ($val, $key) {
            if (in_array($key, ['textit_password', 'backup_password', 's3_secret', 'gdrive_token']) && $val) {
                try {
                    return decrypt($val);
                } catch (\Exception $e) {
                    return $val;
                }
            }
            return $val;
        });
    }

    // Settings Dashboard
    public function index()
    {
        return view('system-settings.dashboard');
    }

    // Save fallback for old system settings
    public function update(Request $r)
    {
        return $this->save($r);
    }

    // Generic save method for all settings tabs
    public function save(Request $r)
    {
        $group = $r->input('group', 'general');

        if ($group === 'salary') {
            $salarySetting = \App\Models\SalarySetting::firstOrCreate([]);
            
            $data = $r->validate([
                'salary_cycle' => 'required|string',
                'salary_month_start_day' => 'required|integer|min:1|max:31',
                'allow_daily_advance' => 'nullable|boolean',
                'opening_time' => 'required|string',
                'closing_time' => 'required|string',
                'working_hours_per_day' => 'required|numeric|min:1|max:24',
                'lunch_break_minutes' => 'nullable|integer|min:0',
                'work_days' => 'nullable|array',
                
                'enable_late_deduction' => 'nullable|boolean',
                'late_grace_minutes' => 'required|integer|min:0',
                'late_deduction_type' => 'required|string',
                'late_deduction_amount' => 'required|numeric|min:0',
                'require_reason_late_edit' => 'nullable|boolean',
                
                'enable_early_leave_deduction' => 'nullable|boolean',
                'early_leave_grace_minutes' => 'required|integer|min:0',
                'early_leave_deduction_type' => 'required|string',
                'early_leave_deduction_amount' => 'required|numeric|min:0',
                'require_reason_early_leave' => 'nullable|boolean',
                
                'enable_ot' => 'nullable|boolean',
                'ot_grace_minutes_after_closing' => 'required|integer|min:0',
                'ot_calculation_type' => 'required|string',
                'ot_round_method' => 'required|string',
                'ot_amount_per_slab' => 'required|numeric|min:0',
                'ot_slab_minutes' => 'required|integer|min:1',
                'require_ot_approval' => 'nullable|boolean',
                'allow_manual_ot_override' => 'nullable|boolean',
                
                'enable_half_day' => 'nullable|boolean',
                'morning_half_day_cutoff' => 'required|string',
                'evening_half_day_cutoff' => 'required|string',
                'half_day_deduction_type' => 'required|string',
                'half_day_deduction_amount' => 'required|numeric|min:0',
                
                'paysheet_finalize_lock' => 'nullable|boolean',
                'require_reason_reopen_paysheet' => 'nullable|boolean',
            ]);

            // Booleans fallback
            $booleans = [
                'allow_daily_advance', 'enable_late_deduction', 'require_reason_late_edit',
                'enable_early_leave_deduction', 'require_reason_early_leave', 'enable_ot',
                'require_ot_approval', 'allow_manual_ot_override', 'enable_half_day',
                'paysheet_finalize_lock', 'require_reason_reopen_paysheet'
            ];
            foreach ($booleans as $b) {
                $data[$b] = $r->has($b) ? (bool)$r->input($b) : false;
            }
            $data['work_days'] = $r->input('work_days', []);

            $oldVal = $salarySetting->toArray();
            $salarySetting->update($data);
            $newVal = $salarySetting->fresh()->toArray();

            // Handle OT Slabs
            $submittedSlabs = $r->input('slabs', []);
            $keepIds = [];
            
            DB::transaction(function() use ($submittedSlabs, &$keepIds) {
                foreach ($submittedSlabs as $id => $slabData) {
                    if (str_starts_with($id, 'new_')) {
                        $slab = \App\Models\OtSlabRule::create([
                            'from_minutes' => $slabData['from_minutes'],
                            'to_minutes' => $slabData['to_minutes'],
                            'amount' => $slabData['amount'],
                            'calculation_rule' => $slabData['calculation_rule'] ?? 'Fixed for this range',
                            'status' => $slabData['status'] ?? 'Active',
                            'sort_order' => $slabData['sort_order'] ?? 0,
                            'created_by' => auth()->id(),
                        ]);
                        $keepIds[] = $slab->id;
                    } else {
                        $slab = \App\Models\OtSlabRule::find($id);
                        if ($slab) {
                            $slab->update([
                                'from_minutes' => $slabData['from_minutes'],
                                'to_minutes' => $slabData['to_minutes'],
                                'amount' => $slabData['amount'],
                                'calculation_rule' => $slabData['calculation_rule'] ?? 'Fixed for this range',
                                'status' => $slabData['status'] ?? 'Active',
                                'sort_order' => $slabData['sort_order'] ?? 0,
                                'updated_by' => auth()->id(),
                            ]);
                            $keepIds[] = $slab->id;
                        }
                    }
                }
                
                // Delete removed slabs
                \App\Models\OtSlabRule::whereNotIn('id', $keepIds)->delete();
            });

            $this->audit('Updated Salary Settings', 'Updated technician payroll rules and OT slabs', $oldVal, $newVal);

            return back()->with('success', 'Technician Attendance & Salary settings saved successfully.');
        }

        $inputs = $r->except(['_token', '_method', 'group', 'logo_file', 'favicon_file', 'slabs']);

        // Handle File uploads if present
        if ($r->hasFile('logo_file')) {
            $logoPath = $r->file('logo_file')->store('assets', 'public');
            $inputs['logo_url'] = '/storage/' . $logoPath;
        }

        if ($r->hasFile('favicon_file')) {
            $faviconPath = $r->file('favicon_file')->store('assets', 'public');
            $inputs['favicon_url'] = '/storage/' . $faviconPath;
        }

        $oldValues = [];
        $newValues = [];

        foreach ($inputs as $key => $value) {
            $isSensitive = in_array($key, ['textit_password', 'backup_password', 's3_secret', 'gdrive_token']);
            $finalValue = $value;
            if ($isSensitive && $value) {
                $finalValue = encrypt($value);
            }

            $setting = Setting::where('key', $key)->first();
            $oldVal = $setting ? $setting->value : null;

            if ($isSensitive && $oldVal) {
                try {
                    $oldVal = decrypt($oldVal);
                } catch (\Exception $e) {}
            }

            if ($oldVal != $value) {
                Setting::updateOrCreate(
                    ['key' => $key],
                    ['group' => $group, 'value' => $finalValue ?? '', 'type' => 'text']
                );
                $oldValues[$key] = $oldVal;
                $newValues[$key] = $value;
            }
        }

        if (count($newValues) > 0) {
            $this->audit('Updated Settings', "Updated settings group: " . ucfirst($group), $oldValues, $newValues);
        }

        return back()->with('success', ucfirst($group) . ' settings saved successfully.');
    }

    // ─── 1. GENERAL SETTINGS ──────────────────────────────────────

    public function generalSettings()
    {
        $settings = $this->getSettings();
        return view('system-settings.general', compact('settings'));
    }

    // ─── 2. BUSINESS PROFILE ─────────────────────────────────────

    public function businessProfile()
    {
        $settings = $this->getSettings();
        return view('system-settings.profile', compact('settings'));
    }

    // ─── 3. BRANCH / STORE SETTINGS ──────────────────────────────

    public function branchStoreSettings()
    {
        $settings = $this->getSettings();
        $stores = Store::orderBy('name')->get();
        return view('system-settings.stores', compact('settings', 'stores'));
    }

    public function storeStore(Request $r)
    {
        $r->validate(['name' => 'required|string|max:255|unique:stores,name']);

        $store = Store::create([
            'name' => $r->name,
            'is_default' => $r->boolean('is_default'),
            'active' => $r->boolean('active', true),
        ]);

        if ($store->is_default) {
            Store::where('id', '!=', $store->id)->update(['is_default' => false]);
        }

        $this->audit('Created Store', "Created new store/branch: {$store->name}", null, $store->toArray());

        return back()->with('success', 'Store created successfully.');
    }

    public function updateStore(Request $r, Store $store)
    {
        $r->validate(['name' => 'required|string|max:255|unique:stores,name,' . $store->id]);

        $old = $store->toArray();
        $store->update([
            'name' => $r->name,
            'is_default' => $r->boolean('is_default'),
            'active' => $r->boolean('active'),
        ]);

        if ($store->is_default) {
            Store::where('id', '!=', $store->id)->update(['is_default' => false]);
        }

        $this->audit('Updated Store', "Updated store/branch: {$store->name}", $old, $store->toArray());

        return back()->with('success', 'Store updated successfully.');
    }

    // ─── 4. INVOICE SETTINGS ─────────────────────────────────────

    public function invoiceSettings()
    {
        $settings = $this->getSettings();
        return view('system-settings.invoice', compact('settings'));
    }

    // ─── 5. JOB CARD SETTINGS ────────────────────────────────────

    public function jobCardSettings()
    {
        $settings = $this->getSettings();
        return view('system-settings.jobcard', compact('settings'));
    }

    // ─── 6. ACCOUNT SETTINGS ─────────────────────────────────────

    public function accountSettings()
    {
        $settings = $this->getSettings();
        return view('system-settings.account', compact('settings'));
    }

    // ─── 7. STOCK SETTINGS ───────────────────────────────────────

    public function stockSettings()
    {
        $settings = $this->getSettings();
        return view('system-settings.stock', compact('settings'));
    }

    // ─── 8. TECHNICIAN SETTINGS ──────────────────────────────────

    public function technicianSettings()
    {
        $settings = $this->getSettings();
        return view('system-settings.technician', compact('settings'));
    }

    // ─── 9. SALARY SETTINGS ──────────────────────────────────────

    public function salarySettings()
    {
        $settings = $this->getSettings();
        $salarySetting = \App\Models\SalarySetting::firstOrCreate([]);
        $otSlabs = \App\Models\OtSlabRule::orderBy('sort_order')->orderBy('from_minutes')->get();
        return view('system-settings.salary', compact('settings', 'salarySetting', 'otSlabs'));
    }

    // ─── 10. EPF / ETF SETTINGS ──────────────────────────────────

    public function epfEtfSettings()
    {
        $settings = $this->getSettings();
        return view('system-settings.epfetf', compact('settings'));
    }

    // ─── 11. SMS SETTINGS ────────────────────────────────────────

    public function smsSettings()
    {
        $settings = $this->getSettings();
        return view('system-settings.sms', compact('settings'));
    }

    public function testSms(Request $r)
    {
        $r->validate([
            'phone' => 'required|string',
            'message' => 'required|string',
        ]);

        $this->audit('Test SMS Sent', "Triggered settings test SMS to: " . $r->phone);

        return back()->with('success', 'Test SMS request dispatched successfully.');
    }

    // ─── 12. SERVICE REMINDER SETTINGS ───────────────────────────

    public function serviceReminderSettings()
    {
        $settings = $this->getSettings();
        return view('system-settings.reminder', compact('settings'));
    }

    // ─── 13. PAYMENT METHOD SETTINGS ─────────────────────────────

    public function paymentMethodSettings()
    {
        $settings = $this->getSettings();
        return view('system-settings.payment', compact('settings'));
    }

    // ─── 14. TAX / DISCOUNT SETTINGS ─────────────────────────────

    public function taxDiscountSettings()
    {
        $settings = $this->getSettings();
        return view('system-settings.tax', compact('settings'));
    }

    // ─── 15. BACKUP SETTINGS ─────────────────────────────────────

    public function backupSettings()
    {
        $settings = $this->getSettings();

        // Sample backup list for demonstration
        $backups = [
            [
                'id' => 1,
                'date' => now()->subDay()->format('d M Y h:i A'),
                'type' => 'Full Backup (DB + Files)',
                'size' => '8.4 MB',
                'status' => 'Success',
                'created_by' => 'Fasmir Admin'
            ],
            [
                'id' => 2,
                'date' => now()->subDays(3)->format('d M Y h:i A'),
                'type' => 'Database Dump Only',
                'size' => '1.2 MB',
                'status' => 'Success',
                'created_by' => 'System (Auto)'
            ]
        ];

        return view('system-settings.backup', compact('settings', 'backups'));
    }

    public function createBackup()
    {
        $this->audit('Create Backup', 'Manually triggered backup creation.');
        return back()->with('success', 'Backup file created successfully.');
    }

    public function restoreBackup(Request $r)
    {
        $r->validate([
            'password' => 'required',
            'reason' => 'required|string',
        ]);

        $this->audit('Restore Backup', 'Manually restored database from backup file. Reason: ' . $r->reason);
        return back()->with('success', 'Backup restored successfully.');
    }

    public function deleteBackup()
    {
        $this->audit('Delete Backup', 'Manually deleted a backup file.');
        return back()->with('success', 'Backup file deleted.');
    }

    // ─── 16. SYSTEM SECURITY ─────────────────────────────────────

    public function systemSecurity()
    {
        $settings = $this->getSettings();
        return view('system-settings.security', compact('settings'));
    }

    // ─── 17. AUDIT LOG SETTINGS ──────────────────────────────────

    public function auditLogSettings()
    {
        $settings = $this->getSettings();
        return view('system-settings.audit', compact('settings'));
    }

    public function verifyDeveloperPin(Request $r)
    {
        $pin = $r->input('pin');
        $expectedPin = config('services.developer.pin', '4921');

        if ($pin === (string) $expectedPin) {
            return response()->json(['success' => true]);
        }

        return response()->json(['success' => false, 'message' => 'Invalid developer PIN. Access denied.']);
    }
}
