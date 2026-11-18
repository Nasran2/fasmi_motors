<?php

namespace App\Http\Controllers;

use App\Models\AccountTransaction;
use App\Models\AuditLog;
use App\Models\ExtraIncome;
use App\Services\ExtraIncomeService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ExtraIncomeController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate([
            'from' => 'nullable|date_format:Y-m-d',
            'to' => ['nullable', 'date_format:Y-m-d', Rule::when($request->filled('from'), 'after_or_equal:from')],
            'q' => 'nullable|string|max:150',
            'destination_account' => ['nullable', Rule::in(array_keys(ExtraIncome::DESTINATIONS))],
            'status' => 'nullable|in:active,deleted,all',
        ]);

        $query = ExtraIncome::query();
        if (($filters['status'] ?? 'active') !== 'active') {
            $query->withTrashed();
            if ($filters['status'] === 'deleted') {
                $query->onlyTrashed();
            }
        }

        $query->when($filters['from'] ?? null, fn ($q, $date) => $q->whereDate('income_date', '>=', $date))
            ->when($filters['to'] ?? null, fn ($q, $date) => $q->whereDate('income_date', '<=', $date))
            ->when($filters['destination_account'] ?? null, fn ($q, $dest) => $q->where('destination_account', $dest))
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->where(fn ($q) => $q
                ->where('number', 'like', "%{$term}%")
                ->orWhere('title', 'like', "%{$term}%")
                ->orWhere('source_name', 'like', "%{$term}%")
                ->orWhere('reference_number', 'like', "%{$term}%")
                ->orWhere('note', 'like', "%{$term}%")
                ->orWhereHas('cheque', fn ($c) => $c
                    ->where('cheque_number', 'like', "%{$term}%")
                    ->orWhere('bank_name', 'like', "%{$term}%"))));

        $total = (float) (clone $query)->whereNull('deleted_at')->where('status', 'Completed')->sum('amount');
        $holdTotal = (float) (clone $query)->whereNull('deleted_at')->where('status', 'Hold')->sum('amount');
        $records = $query->with(['account', 'creator', 'cheque'])->latest('income_date')->latest('id')->paginate(20)->withQueryString();
        $destinations = ExtraIncome::DESTINATIONS;

        return view('extra-money.index', compact('records', 'destinations', 'total', 'holdTotal'));
    }

    public function create(ExtraIncomeService $service)
    {
        $extraIncome = new ExtraIncome([
            'income_date' => today(),
            'destination_account' => 'main',
            'payment_method' => 'Cash',
        ]);

        return $this->form($extraIncome, $service);
    }

    public function store(Request $request, ExtraIncomeService $service)
    {
        $extraIncome = $service->save($this->validateRecord($request));
        $message = $extraIncome->status === 'Hold'
            ? 'Extra money cheque recorded on hold in Cheque Management. It will be credited as Bank Account income once marked Passed.'
            : 'Extra money saved as income and account balance updated.';

        if ($request->input('action') === 'add_another') {
            return redirect()->route('extra-money.create')->with('success', $message);
        }

        return redirect()->route('extra-money.show', $extraIncome)->with('success', $message);
    }

    public function show(ExtraIncome $extraIncome)
    {
        $extraIncome->load(['account', 'creator', 'editor', 'cheque.bankAccount', 'cheque.forwardedSupplier']);
        $transactions = AccountTransaction::where('reference_type', $extraIncome->getMorphClass())
            ->where('reference_id', $extraIncome->id)
            ->with('account')
            ->orderBy('id')
            ->get();
        $history = AuditLog::where('auditable_type', $extraIncome->getMorphClass())
            ->where('auditable_id', $extraIncome->id)
            ->latest('id')
            ->get();

        return view('extra-money.show', compact('extraIncome', 'transactions', 'history'));
    }

    public function edit(ExtraIncome $extraIncome, ExtraIncomeService $service)
    {
        $extraIncome->load('cheque');

        return $this->form($extraIncome, $service);
    }

    public function update(Request $request, ExtraIncome $extraIncome, ExtraIncomeService $service)
    {
        $extraIncome = $service->save($this->validateRecord($request, true), $extraIncome);
        $message = $extraIncome->status === 'Hold'
            ? 'Extra money updated and cheque recorded on hold in Cheque Management.'
            : 'Extra money updated as income and account balances corrected.';

        return redirect()->route('extra-money.show', $extraIncome)->with('success', $message);
    }

    public function destroy(Request $request, ExtraIncome $extraIncome, ExtraIncomeService $service)
    {
        $data = $request->validate([
            'reason' => 'required|string|min:5|max:500',
            'record_version' => 'required|string|size:64',
        ]);
        $service->delete($extraIncome, $data['reason'], $data['record_version']);

        return redirect()->route('extra-money.index')->with('success', 'Extra money deleted and its income ledger credit reversed.');
    }

    public function options(Request $request, ExtraIncomeService $service)
    {
        $data = $request->validate(['date' => 'required|date_format:Y-m-d']);

        return response()->json($service->options($data['date']))
            ->header('Cache-Control', 'no-store');
    }

    private function form(ExtraIncome $extraIncome, ExtraIncomeService $service)
    {
        $date = old('income_date', $extraIncome->income_date?->toDateString() ?: today()->toDateString());
        $optionState = $service->options($date);

        return view('extra-money.form', [
            'extraIncome' => $extraIncome,
            'optionState' => $optionState,
            'paymentMethods' => ExtraIncome::PAYMENT_METHODS,
            'chequeStatuses' => ExtraIncome::CHEQUE_INITIAL_STATUSES,
        ]);
    }

    private function validateRecord(Request $request, bool $editing = false): array
    {
        return $request->validate([
            'income_date' => 'required|date_format:Y-m-d|before_or_equal:today',
            'title' => 'required|string|max:150',
            'source_name' => 'nullable|string|max:150',
            'destination_account' => ['required', 'string', Rule::in(array_keys(ExtraIncome::DESTINATIONS))],
            'amount' => 'required|numeric|decimal:0,2|gt:0|max:999999999999.99',
            'payment_method' => ['required', Rule::in(ExtraIncome::PAYMENT_METHODS)],
            'reference_number' => 'nullable|string|max:100',
            'note' => 'nullable|string|max:1000',
            'cheque_date' => 'nullable|required_if:payment_method,Cheque|date_format:Y-m-d',
            'cheque_number' => 'nullable|required_if:payment_method,Cheque|string|max:80',
            'bank_name' => 'nullable|required_if:payment_method,Cheque|string|max:120',
            'branch' => 'nullable|string|max:120',
            'cheque_status' => ['nullable', 'required_if:payment_method,Cheque', Rule::in(array_keys(ExtraIncome::CHEQUE_INITIAL_STATUSES))],
            'realized_date' => [
                'nullable',
                Rule::requiredIf(fn () => $request->input('payment_method') === 'Cheque' && $request->input('cheque_status') === 'Cleared'),
                'date_format:Y-m-d',
                'before_or_equal:today',
            ],
            'reason' => ($editing ? 'required' : 'nullable').'|string|min:5|max:500',
            'record_version' => ($editing ? 'required' : 'nullable').'|string|size:64',
        ]);
    }
}
