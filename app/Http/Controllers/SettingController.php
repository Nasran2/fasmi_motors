<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\LeaveHolidaySetting;
use App\Models\OtSlabRule;
use App\Models\SalaryCycleSetting;
use App\Models\SalarySetting;
use App\Models\Setting;
use App\Models\SmsLog;
use App\Models\SmsSetting;
use App\Models\Store;
use App\Services\DatabaseBackupService;
use App\Services\HolidayService;
use App\Services\Sms\TextitSmsService;
use App\Services\Sms\WorkshopSmsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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

        if ($group === 'sms') {
            return $this->saveSms($r);
        }

        if ($group === 'salary') {
            $salarySetting = SalarySetting::firstOrCreate([]);

            $data = $r->validate([
                'salary_cycle' => 'required|string',
                'salary_month_start_day' => 'required|integer|min:1|max:31',
                'salary_cycle_mode' => 'required|in:normal_calendar,custom_cutoff',
                'custom_cutoff_enabled' => 'nullable|boolean',
                'cycle_start_rule' => 'nullable|string|max:100',
                'cycle_end_day' => 'nullable|integer|min:1|max:31',
                'carry_missing_days' => 'nullable|boolean',
                'allow_daily_advance' => 'nullable|boolean',
                'opening_time' => 'required|string',
                'closing_time' => 'required|string',
                'working_hours_per_day' => 'required|numeric|min:1|max:24',
                'lunch_break_minutes' => 'nullable|integer|min:0',
                'work_days' => 'nullable|array',
                'work_days.*' => 'string|distinct|in:Monday,Tuesday,Wednesday,Thursday,Friday,Saturday,Sunday',

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

                'slip_show_attendance' => 'nullable|boolean',
                'slip_paper_size' => 'required|string',
            ]);

            // Booleans fallback
            $booleans = [
                'allow_daily_advance', 'custom_cutoff_enabled', 'carry_missing_days', 'enable_late_deduction', 'require_reason_late_edit',
                'enable_early_leave_deduction', 'require_reason_early_leave', 'enable_ot',
                'require_ot_approval', 'allow_manual_ot_override', 'enable_half_day',
                'paysheet_finalize_lock', 'require_reason_reopen_paysheet', 'slip_show_attendance',
            ];
            foreach ($booleans as $b) {
                $data[$b] = $r->has($b) ? (bool) $r->input($b) : false;
            }
            $data['work_days'] = $r->input('work_days', []);

            $oldVal = $salarySetting->toArray();
            $cycleData = [
                'salary_cycle_mode' => $data['salary_cycle_mode'],
                'custom_cutoff_enabled' => $data['custom_cutoff_enabled'],
                'cycle_start_rule' => ($data['cycle_start_rule'] ?? null) ?: 'previous_month_balance_day',
                'cycle_end_day' => ($data['cycle_end_day'] ?? null) ?: 30,
                'carry_missing_days' => $data['carry_missing_days'],
                'updated_by' => auth()->id(),
            ];
            unset($data['salary_cycle_mode'], $data['custom_cutoff_enabled'], $data['cycle_start_rule'], $data['cycle_end_day'], $data['carry_missing_days']);

            DB::transaction(function () use ($salarySetting, $data) {
                $salarySetting->update($data);
                LeaveHolidaySetting::updateOrCreate(['key' => 'weekly_leave_days'], ['value' => json_encode(array_values(array_diff(HolidayService::DAYS, $data['work_days'])))]);
            });
            SalaryCycleSetting::current()->update($cycleData);
            $newVal = $salarySetting->fresh()->toArray();

            // Handle OT Slabs
            $submittedSlabs = $r->input('slabs', []);
            $keepIds = [];

            DB::transaction(function () use ($submittedSlabs, &$keepIds) {
                foreach ($submittedSlabs as $id => $slabData) {
                    if (str_starts_with($id, 'new_')) {
                        $slab = OtSlabRule::create([
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
                        $slab = OtSlabRule::find($id);
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
                OtSlabRule::whereNotIn('id', $keepIds)->delete();
            });

            $this->audit('Updated Salary Settings', 'Updated technician payroll rules and OT slabs', $oldVal, $newVal);

            return back()->with('success', 'Technician Attendance & Salary settings saved successfully.');
        }

        if ($group === 'invoice') {
            $r->validate([
                'show_due_invoice' => ['sometimes', 'required', 'boolean'],
                'quotation_intro_heading' => ['nullable', 'string', 'max:100'],
                'quotation_intro_text' => ['nullable', 'string', 'max:2000'],
                'quotation_default_note' => ['nullable', 'string', 'max:1000'],
                'bill_intro_heading' => ['nullable', 'string', 'max:100'],
                'bill_intro_text' => ['nullable', 'string', 'max:2000'],
                'bill_default_note' => ['nullable', 'string', 'max:1000'],
                'customer_note_heading' => ['nullable', 'string', 'max:100'],
                'terms_heading' => ['nullable', 'string', 'max:100'],
                'terms_and_conditions' => ['nullable', 'string', 'max:5000'],
                'invoice_footer_note' => ['nullable', 'string', 'max:300'],
            ]);
        }

        if ($group === 'payment') {
            $chargeRules = [];
            foreach (['pm_cash', 'pm_card', 'pm_bank', 'pm_cheque', 'pm_qr'] as $key) {
                $chargeRules[$key.'_charge_percent'] = ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:100'];
                $chargeRules[$key.'_charge_account'] = ['nullable', 'in:bank,main,spare_parts'];
            }
            $r->validate($chargeRules);
        }

        $inputs = $r->except(['_token', '_method', 'group', 'logo_file', 'favicon_file', 'slabs']);
        if ($group === 'invoice' && $r->has('show_due_invoice')) {
            $inputs['show_due_invoice'] = $r->boolean('show_due_invoice') ? '1' : '0';
        }
        if ($group === 'payment') {
            foreach (['pm_cash', 'pm_card', 'pm_bank', 'pm_cheque', 'pm_qr'] as $key) {
                if (array_key_exists($key.'_charge_percent', $inputs)) {
                    $defaultRate = $key === 'pm_card' ? 2.5 : 0.0;
                    $rate = $inputs[$key.'_charge_percent'] === null || $inputs[$key.'_charge_percent'] === '' ? $defaultRate : (float) $inputs[$key.'_charge_percent'];
                    $inputs[$key.'_charge_percent'] = rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.') ?: '0';
                }
            }
        }

        // Handle File uploads if present
        if ($r->hasFile('logo_file')) {
            $logoPath = $r->file('logo_file')->store('assets', 'public');
            $inputs['logo_url'] = '/storage/'.$logoPath;
        }

        if ($r->hasFile('favicon_file')) {
            $faviconPath = $r->file('favicon_file')->store('assets', 'public');
            $inputs['favicon_url'] = '/storage/'.$faviconPath;
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
                } catch (\Exception $e) {
                }
            }

            if ((string) ($oldVal ?? '') !== (string) ($value ?? '')) {
                Setting::updateOrCreate(
                    ['key' => $key],
                    ['group' => $group, 'value' => $finalValue ?? '', 'type' => 'text']
                );
                $oldValues[$key] = $oldVal;
                $newValues[$key] = $value;
            }
        }

        if (count($newValues) > 0) {
            $this->audit('Updated Settings', 'Updated settings group: '.ucfirst($group), $oldValues, $newValues);
        }

        return back()->with('success', ucfirst($group).' settings saved successfully.');
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
        $r->validate(['name' => 'required|string|max:255|unique:stores,name,'.$store->id]);

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
        $salarySetting = SalarySetting::firstOrCreate([]);
        $salaryCycleSetting = SalaryCycleSetting::current();
        $otSlabs = OtSlabRule::orderBy('sort_order')->orderBy('from_minutes')->get();

        return view('system-settings.salary', compact('settings', 'salarySetting', 'salaryCycleSetting', 'otSlabs'));
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
        $gateway = SmsSetting::instance();
        foreach (['sms_provider' => 'provider_name', 'sms_gateway_url' => 'gateway_url', 'textit_id' => 'textit_id', 'sms_api_method' => 'api_method', 'sms_country_code' => 'default_country_code', 'sms_sender_id' => 'sender_id', 'sms_gateway_enabled' => 'enabled', 'sms_auto_enabled' => 'auto_sms_enabled'] as $key => $attribute) {
            $settings[$key] = $gateway->$attribute;
        }
        unset($settings['textit_password']);
        $events = WorkshopSmsService::events();
        $publicUrlReady = WorkshopSmsService::hasPublicBaseUrl();
        $smsLogs = SmsLog::latest()->paginate(10);

        return view('system-settings.sms', compact('settings', 'smsLogs', 'events', 'publicUrlReady'));
    }

    private function saveSms(Request $request)
    {
        $rules = [
            'sms_provider' => 'required|string|max:80', 'sms_gateway_url' => 'required|url|max:255',
            'textit_id' => 'required|string|max:80', 'textit_password' => 'nullable|string|max:255',
            'sms_api_method' => 'required|in:GET,POST', 'sms_country_code' => 'required|in:94',
            'sms_sender_id' => 'nullable|string|max:11', 'sms_gateway_enabled' => 'required|boolean',
            'sms_auto_enabled' => 'required|boolean',
            'sms_reminders_enabled' => 'nullable|boolean',
        ];
        foreach (WorkshopSmsService::events() as $event => $definition) {
            $rules['sms_event_'.$event] = 'required|boolean';
            $rules['sms_tmpl_'.$event] = 'required|string|max:1000';
        }
        $data = $request->validate($rules);
        DB::transaction(function () use ($data) {
            $gateway = ['provider_name' => $data['sms_provider'], 'gateway_url' => $data['sms_gateway_url'], 'textit_id' => $data['textit_id'],
                'api_method' => $data['sms_api_method'], 'default_country_code' => $data['sms_country_code'], 'sender_id' => $data['sms_sender_id'] ?? null,
                'enabled' => $data['sms_gateway_enabled'], 'auto_sms_enabled' => $data['sms_auto_enabled'], 'updated_by' => auth()->id()];
            if (! empty($data['textit_password'])) {
                $gateway['textit_password'] = $data['textit_password'];
            }
            SmsSetting::instance()->update($gateway);
            foreach ($data as $key => $value) {
                if ($key === 'textit_password') {
                    continue;
                }
                Setting::updateOrCreate(['key' => $key], ['group' => 'sms', 'value' => $value ?? '', 'type' => 'text']);
            }
            // Keep the existing reminder scheduler's switch in sync.
            Setting::updateOrCreate(['key' => 'reminder_auto_send'], ['group' => 'service_reminders', 'value' => $data['sms_reminders_enabled'] ?? '0']);
        });

        $this->audit('Updated SMS Settings', 'Updated SMS gateway, event switches and templates.');

        return back()->with('success', 'SMS settings saved.');
    }

    public function testSms(Request $r, TextitSmsService $sms)
    {
        $data = $r->validate(['phone' => 'required|string|max:20', 'message' => 'required|string|max:1000']);
        if (! SmsSetting::instance()->manual_sms_enabled) {
            return back()->with('error', 'Manual SMS sending is switched off in SMS Center settings.');
        }
        $result = $sms->send($data['phone'], $data['message'], ['module' => 'test', 'message_type' => 'Test SMS']);
        $this->audit('Test SMS Attempt', 'SMS gateway test: '.$result['status']);

        return back()->with($result['success'] ? 'success' : 'error', $result['success'] ? 'SMS accepted by provider. Message ID: '.($result['message_id'] ?? 'N/A') : 'SMS failed: '.$result['error']);
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

        $backups = app(DatabaseBackupService::class)->backups();

        return view('system-settings.backup', compact('settings', 'backups'));
    }

    public function createBackup()
    {
        $file = app(DatabaseBackupService::class)->create();
        $this->audit('Create Backup', 'Created database backup: '.$file);

        return back()->with('success', 'Backup file created successfully.');
    }

    public function restoreBackup(Request $r)
    {
        $r->validate([
            'password' => 'required|current_password',
            'reason' => 'required|string',
        ]);

        throw ValidationException::withMessages(['backup' => 'Online restoration is unavailable. Restore a verified SQL backup during an administrator-controlled maintenance window. No database changes were made.']);
    }

    public function deleteBackup(Request $request)
    {
        $data = $request->validate(['backup_id' => 'required|string|max:100']);
        app(DatabaseBackupService::class)->delete($data['backup_id']);
        $this->audit('Delete Backup', 'Deleted database backup: '.$data['backup_id']);

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
