<?php

namespace App\Http\Controllers;

use App\Models\JobAdvance;
use App\Models\JobCard;
use App\Models\Setting;
use App\Models\SmsSetting;
use App\Services\JobAdvanceService;
use App\Services\SettlementService;
use App\Services\Sms\TextitSmsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class JobAdvanceController extends Controller
{
    public function __invoke(Request $request, JobCard $job, JobAdvanceService $service): RedirectResponse
    {
        [$data, $row] = $this->validated($request);
        $advance = $service->receive($job, $data, $row);
        $message = $this->notify($job, $advance, 'Advance saved. It covers the current job first, then old balances when billing.');

        return redirect()->route('jobs.show', $job)->with('success', $message);
    }

    public function update(Request $request, JobCard $job, JobAdvance $advance, JobAdvanceService $service): RedirectResponse
    {
        [$data, $row] = $this->validated($request, true);
        $replacement = $service->correct($job, $advance, $data, $row);
        $message = $this->notify($job, $replacement, 'Advance corrected. The original receipt and reason are saved in the change history.', true);

        return redirect()->route('jobs.show', $job)->with('success', $message);
    }

    public function destroy(Request $request, JobCard $job, JobAdvance $advance, JobAdvanceService $service): RedirectResponse
    {
        $data = $request->validate(['advance_reason' => 'required|string|min:5|max:500']);
        $service->cancel($job, $advance, $data['advance_reason']);

        return redirect()->route('jobs.show', $job)->with('success', 'Advance deleted and its ledger entries reversed. The receipt and reason remain in the change history.');
    }

    private function validated(Request $request, bool $editing = false): array
    {
        $data = $request->validate([
            'advance_reason' => $editing ? 'required|string|min:5|max:500' : 'nullable|string|max:500',
            'advance_amount' => 'required|numeric|decimal:0,2|min:0.01|max:999999999999.99',
            'advance_method' => 'required|in:Cash,Card,Bank Transfer,Cheque,QR',
            'advance_account_id' => ['required', 'integer', Rule::exists('accounts', 'id')->where('active', true)->where('shop_type', session('shop_mode', 'workshop'))],
            'advance_paid_at' => 'required|date_format:Y-m-d|before_or_equal:today',
            'advance_cheque_date' => 'nullable|required_if:advance_method,Cheque|date_format:Y-m-d',
            'advance_cheque_number' => 'nullable|required_if:advance_method,Cheque|string|max:80',
            'advance_bank_name' => 'nullable|required_if:advance_method,Cheque|string|max:120',
            'advance_note' => 'nullable|string|max:500', 'send_advance_sms' => 'required|boolean',
        ]);
        $rows = app(SettlementService::class)->rows(['payments' => [[
            'amount' => $data['advance_amount'], 'method' => $data['advance_method'], 'account_id' => $data['advance_account_id'],
            'cheque_date' => $data['advance_cheque_date'] ?? null, 'cheque_number' => $data['advance_cheque_number'] ?? null, 'bank_name' => $data['advance_bank_name'] ?? null,
        ]]]);

        return [$data, $rows[0]];
    }

    private function notify(JobCard $job, JobAdvance $advance, string $message, bool $corrected = false): string
    {

        if ($advance->send_sms) {
            try {
                $customer = $job->customer;
                $text = 'Dear '.($customer?->name ?: 'Customer').", job card {$job->number}".($job->bike?->number ? " for bike {$job->bike->number}" : '').($corrected ? ': corrected advance payment Rs. ' : ': advance payment Rs. ').number_format($advance->amount, 2)
                    .($advance->status === 'Hold' ? ' received by cheque (on hold until cleared).' : ' received.')." Receipt: {$advance->number}. - ".Setting::valueFor('business_name', 'Fasmir Motors');
                $options = [
                    'module' => 'Workshop', 'message_type' => 'Job advance', 'recipient_name' => $customer?->name, 'recipient_type' => 'customer',
                    'reference_no' => $job->number, 'related_type' => $advance->getMorphClass(), 'related_id' => $advance->id, 'sent_by' => auth()->id(),
                ];
                $result = SmsSetting::instance()->manual_sms_enabled
                    ? app(TextitSmsService::class)->send((string) $customer?->phone, $text, $options)
                    : ['success' => false, 'error' => 'Manual SMS sending is switched off in SMS settings.'];
                $message .= $result['success'] ? ' Customer SMS sent.' : ' Customer SMS was not sent: '.($result['error'] ?? 'check SMS logs.');
            } catch (\Throwable $exception) {
                Log::error('Job advance SMS failed.', ['advance_id' => $advance->id, 'exception' => $exception::class]);
                $message .= ' Customer SMS could not be sent; check SMS logs/settings.';
            }
        }

        return $message;
    }
}
