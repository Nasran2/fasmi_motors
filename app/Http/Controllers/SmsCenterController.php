<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\SmsLog;
use App\Models\SmsSetting;
use App\Models\SmsTemplate;
use App\Models\Technician;
use App\Models\User;
use App\Services\Sms\TextitSmsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SmsCenterController extends Controller
{
    // ─────────────────────────────────────────────────────────
    // DASHBOARD
    // ─────────────────────────────────────────────────────────

    public function dashboard()
    {
        $today = today()->toDateString();
        $thisMonth = today()->startOfMonth()->toDateString();

        $stats = [
            'sent_today' => SmsLog::whereDate('created_at', $today)->count(),
            'sent_month' => SmsLog::whereDate('created_at', '>=', $thisMonth)->count(),
            'successful' => SmsLog::where('status', 'sent')->count(),
            'failed' => SmsLog::where('status', 'failed')->count(),
            'pending' => SmsLog::where('status', 'pending')->count(),
        ];

        $recent = SmsLog::with('sender')
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        $byModule = SmsLog::select('module', DB::raw('count(*) as count'))
            ->whereNotNull('module')
            ->groupBy('module')
            ->orderByDesc('count')
            ->limit(8)
            ->get();

        $settings = SmsSetting::instance();

        return view('sms.dashboard', compact('stats', 'recent', 'byModule', 'settings'));
    }

    // ─────────────────────────────────────────────────────────
    // SEND SMS
    // ─────────────────────────────────────────────────────────

    public function send()
    {
        $templates = SmsTemplate::where('status', 'active')
            ->orderBy('category')
            ->orderBy('name')
            ->get();

        return view('sms.send', compact('templates'));
    }

    public function sendPost(Request $request, TextitSmsService $sms)
    {
        $data = $request->validate([
            'phone' => 'required|string|min:7|max:20',
            'message' => 'required|string|max:918',
            'module' => 'nullable|string|max:80',
            'message_type' => 'nullable|string|max:80',
            'recipient_name' => 'nullable|string|max:120',
            'recipient_type' => 'nullable|string|max:40',
            'reference_no' => 'nullable|string|max:15',
        ]);

        $result = $sms->send($data['phone'], $data['message'], [
            'module' => $data['module'] ?? 'manual',
            'message_type' => $data['message_type'] ?? 'Manual SMS',
            'recipient_name' => $data['recipient_name'] ?? null,
            'recipient_type' => $data['recipient_type'] ?? 'custom',
            'reference_no' => $data['reference_no'] ?? null,
            'sent_by' => auth()->id(),
        ]);

        if ($result['success']) {
            return back()->with('success', 'SMS sent successfully! Message ID: '.($result['message_id'] ?? 'N/A'));
        }

        return back()->with('error', $result['error'] ?? 'SMS sending failed. Please check settings.')->withInput();
    }

    // ─────────────────────────────────────────────────────────
    // TEMPLATES
    // ─────────────────────────────────────────────────────────

    public function templates()
    {
        $templates = SmsTemplate::orderBy('category')->orderBy('name')->paginate(20);

        $stats = [
            'total' => SmsTemplate::count(),
            'active' => SmsTemplate::where('status', 'active')->count(),
            'auto' => SmsTemplate::where('auto_send', true)->count(),
            'manual' => SmsTemplate::where('auto_send', false)->count(),
        ];

        return view('sms.templates', compact('templates', 'stats'));
    }

    public function templateCreate()
    {
        return view('sms.template-form', ['template' => null]);
    }

    public function templateStore(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'category' => 'required|string|max:80',
            'body' => 'required|string|max:918',
            'auto_send' => 'nullable|boolean',
            'status' => 'required|in:active,inactive',
            'event' => 'nullable|string|max:80',
        ]);

        SmsTemplate::create(array_merge($data, [
            'auto_send' => (bool) ($data['auto_send'] ?? false),
            'created_by' => auth()->id(),
            'updated_by' => auth()->id(),
        ]));

        return redirect()->route('sms.templates')->with('success', 'SMS template created successfully.');
    }

    public function templateEdit(SmsTemplate $template)
    {
        return view('sms.template-form', compact('template'));
    }

    public function templateUpdate(Request $request, SmsTemplate $template)
    {
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'category' => 'required|string|max:80',
            'body' => 'required|string|max:918',
            'auto_send' => 'nullable|boolean',
            'status' => 'required|in:active,inactive',
            'event' => 'nullable|string|max:80',
        ]);

        $template->update(array_merge($data, [
            'auto_send' => (bool) ($data['auto_send'] ?? false),
            'updated_by' => auth()->id(),
        ]));

        return redirect()->route('sms.templates')->with('success', 'Template updated successfully.');
    }

    public function templateDestroy(SmsTemplate $template)
    {
        $template->delete();

        return back()->with('success', 'Template deleted.');
    }

    public function templateToggle(SmsTemplate $template)
    {
        $template->update([
            'status' => $template->status === 'active' ? 'inactive' : 'active',
            'updated_by' => auth()->id(),
        ]);

        return back()->with('success', 'Template status updated.');
    }

    // ─────────────────────────────────────────────────────────
    // LOGS
    // ─────────────────────────────────────────────────────────

    public function logs(Request $request)
    {
        $request->validate([
            'date_range' => ['nullable', 'in:today,yesterday,this_week,last_week,this_month,last_month,this_year,custom'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $from = $request->input('from', today()->toDateString());
        $to = $request->input('to', today()->toDateString());
        $range = $request->input('date_range', 'today');

        [$from, $to] = $this->resolveDateRange($range, $from, $to);

        $logs = SmsLog::with('sender')
            ->when($request->search, fn ($q, $s) => $q->where(fn ($x) => $x->where('phone', 'like', "%{$s}%")
                ->orWhere('phone_number', 'like', "%{$s}%")
                ->orWhere('recipient_name', 'like', "%{$s}%")
                ->orWhere('message', 'like', "%{$s}%")
            ))
            ->when($request->status, fn ($q, $v) => $q->where('status', $v))
            ->when($request->module, fn ($q, $v) => $q->where('module', $v))
            ->when($request->message_type, fn ($q, $v) => $q->where('message_type', $v))
            ->whereDate('created_at', '>=', $from)
            ->whereDate('created_at', '<=', $to)
            ->orderByDesc('created_at')
            ->paginate(25)
            ->withQueryString();

        $stats = [
            'total' => SmsLog::count(),
            'sent' => SmsLog::where('status', 'sent')->count(),
            'delivered' => SmsLog::where('status', 'delivered')->count(),
            'failed' => SmsLog::where('status', 'failed')->count(),
            'pending' => SmsLog::where('status', 'pending')->count(),
        ];

        $modules = SmsLog::select('module')->whereNotNull('module')->distinct()->pluck('module');
        $users = User::orderBy('name')->get(['id', 'name']);

        return view('sms.logs', compact('logs', 'stats', 'from', 'to', 'range', 'modules', 'users'));
    }

    // ─────────────────────────────────────────────────────────
    // FAILED SMS
    // ─────────────────────────────────────────────────────────

    public function failed(Request $request)
    {
        $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $from = $request->input('from', today()->startOfMonth()->toDateString());
        $to = $request->input('to', today()->toDateString());

        $failed = SmsLog::with('sender')
            ->where('status', 'failed')
            ->when($request->search, fn ($q, $s) => $q->where(fn ($x) => $x->where('phone', 'like', "%{$s}%")
                ->orWhere('phone_number', 'like', "%{$s}%")
                ->orWhere('recipient_name', 'like', "%{$s}%")
            ))
            ->when($request->reason, fn ($q, $v) => $q->where('failure_reason', 'like', "%{$v}%"))
            ->when($request->module, fn ($q, $v) => $q->where('module', $v))
            ->whereDate('created_at', '>=', $from)
            ->whereDate('created_at', '<=', $to)
            ->orderByDesc('created_at')
            ->paginate(25)
            ->withQueryString();

        $stats = [
            'failed_today' => SmsLog::where('status', 'failed')->whereDate('created_at', today())->count(),
            'failed_month' => SmsLog::where('status', 'failed')->whereDate('created_at', '>=', today()->startOfMonth())->count(),
            'invalid' => SmsLog::where('status', 'failed')->where('failure_reason', 'like', '%Invalid%')->count(),
            'provider' => SmsLog::where('status', 'failed')->where('failure_reason', 'like', '%Provider%')->count(),
            'resent' => SmsLog::where('status', 'failed')->where('retry_count', '>', 0)->count(),
        ];

        $modules = SmsLog::select('module')->whereNotNull('module')->distinct()->pluck('module');

        return view('sms.failed', compact('failed', 'stats', 'from', 'to', 'modules'));
    }

    public function resend(SmsLog $smsLog, TextitSmsService $sms)
    {
        $result = $sms->resend($smsLog, auth()->id());

        if ($result['success']) {
            if (in_array(strtolower($smsLog->status), ['queued', 'pending'])) {
                $smsLog->delete();
            }

            return back()->with('success', 'SMS sent successfully!');
        }

        if (in_array(strtolower($smsLog->status), ['queued', 'pending'])) {
            $smsLog->delete();
        }

        return back()->with('error', 'Send failed: '.($result['error'] ?? 'Unknown error'));
    }

    // ─────────────────────────────────────────────────────────
    // SETTINGS
    // ─────────────────────────────────────────────────────────

    public function settings()
    {
        $settings = SmsSetting::instance();

        return view('sms.settings', compact('settings'));
    }

    public function settingsUpdate(Request $request)
    {
        $data = $request->validate([
            'provider_name' => 'required|string|max:80',
            'enabled' => 'nullable|boolean',
            'api_method' => 'required|in:GET,POST',
            'gateway_url' => 'required|url|max:255',
            'textit_id' => 'nullable|string|max:80',
            'textit_password' => 'nullable|string|max:255',
            'default_country_code' => 'required|string|max:5',
            'sender_id' => 'nullable|string|max:11',
            'id_param' => 'required|string|max:20',
            'password_param' => 'required|string|max:20',
            'to_param' => 'required|string|max:20',
            'text_param' => 'required|string|max:20',
            'ref_param' => 'nullable|string|max:20',
            'schedule_param' => 'nullable|string|max:20',
            'success_keyword' => 'required|string|max:20',
            'auto_sms_enabled' => 'nullable|boolean',
            'manual_sms_enabled' => 'nullable|boolean',
        ]);

        $data['enabled'] = (bool) $request->input('enabled', false);
        $data['auto_sms_enabled'] = (bool) $request->input('auto_sms_enabled', false);
        $data['manual_sms_enabled'] = (bool) $request->input('manual_sms_enabled', true);
        $data['updated_by'] = auth()->id();

        // Only update password if provided
        if (empty($data['textit_password'])) {
            unset($data['textit_password']);
        }

        SmsSetting::instance()->update($data);

        return back()->with('success', 'SMS provider settings saved successfully.');
    }

    public function testSms(Request $request, TextitSmsService $sms)
    {
        $data = $request->validate([
            'test_phone' => 'required|string|min:7|max:20',
            'test_message' => 'required|string|max:160',
        ]);

        $result = $sms->send($data['test_phone'], $data['test_message'], [
            'module' => 'test',
            'message_type' => 'Test SMS',
            'sent_by' => auth()->id(),
        ]);

        if ($result['success']) {
            return back()->with('success', 'Test SMS sent! Message ID: '.($result['message_id'] ?? 'N/A'));
        }

        return back()->with('error', 'Test failed: '.($result['error'] ?? 'Unknown error'));
    }

    // ─────────────────────────────────────────────────────────
    // AJAX — Search Recipients
    // ─────────────────────────────────────────────────────────

    public function searchRecipients(Request $request)
    {
        $data = $request->validate([
            'type' => ['nullable', 'in:customer,technician'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $type = $data['type'] ?? 'customer';
        $query = $data['q'] ?? '';

        if ($type === 'customer') {
            $results = Customer::where(fn ($q) => $q->where('name', 'like', "%{$query}%")
                ->orWhere('phone', 'like', "%{$query}%")
            )->limit(10)->get(['id', 'name', 'phone']);
        } elseif ($type === 'technician') {
            $results = Technician::where(fn ($q) => $q->where('name', 'like', "%{$query}%")
                ->orWhere('phone', 'like', "%{$query}%")
            )->limit(10)->get(['id', 'name', 'phone']);
        } else {
            $results = collect();
        }

        return response()->json($results);
    }

    // ─────────────────────────────────────────────────────────
    // Helper
    // ─────────────────────────────────────────────────────────

    protected function resolveDateRange(string $range, string $from, string $to): array
    {
        return match ($range) {
            'today' => [today()->toDateString(), today()->toDateString()],
            'yesterday' => [today()->subDay()->toDateString(), today()->subDay()->toDateString()],
            'this_week' => [today()->startOfWeek()->toDateString(), today()->toDateString()],
            'last_week' => [today()->subWeek()->startOfWeek()->toDateString(), today()->subWeek()->endOfWeek()->toDateString()],
            'this_month' => [today()->startOfMonth()->toDateString(), today()->toDateString()],
            'last_month' => [today()->subMonth()->startOfMonth()->toDateString(), today()->subMonth()->endOfMonth()->toDateString()],
            'this_year' => [today()->startOfYear()->toDateString(), today()->toDateString()],
            default => [$from, $to],
        };
    }
}
