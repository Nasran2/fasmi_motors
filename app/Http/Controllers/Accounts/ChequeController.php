<?php

namespace App\Http\Controllers\Accounts;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Cheque;
use App\Models\User;
use App\Services\SettlementService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ChequeController extends BaseAccountController
{
    public function index(Request $request)
    {
        return view('accounts.cheques', $this->registerData($request));
    }

    private function registerData(Request $request): array
    {
        $status = $request->route('cheque_status') ?? $request->input('status', '');
        $type = $request->route('cheque_type') ?? $request->input('type', '');
        validator(['status' => $status, 'type' => $type], [
            'status' => 'nullable|in:Pending,Deposited,Cleared,Returned,Cancelled,Deleted',
            'type' => 'nullable|in:Received,Issued,Forwarded',
        ])->validate();
        $request->validate(['search' => 'nullable|string|max:120', 'date_range' => 'nullable|in:all,today,yesterday,this_week,last_week,this_month,last_month,this_year,last_year,custom']);
        $range = $request->input('date_range', 'all');
        $from = $to = null;
        $base = Cheque::with(['creator', 'bankAccount', 'forwardedSupplier']);
        if ($range !== 'all') {
            [$from, $to, $range] = $this->range($request);
            $base->whereBetween('cheque_date', [$from->toDateString(), $to->toDateString()]);
        }
        if ($status === 'Deleted') {
            $base->onlyTrashed();
        } elseif ($status === 'Pending') {
            $base->whereIn('status', ['Pending', 'Deposited']);
        } elseif ($status) {
            $base->where('status', $status);
        }
        if ($type === 'Forwarded') {
            $base->whereNotNull('forwarded_supplier_id');
        } elseif ($type === 'Issued') {
            $base->where(fn ($q) => $q->where('cheque_type', 'Issued')->orWhereNotNull('forwarded_supplier_id'));
        } elseif ($type) {
            $base->where('cheque_type', $type);
        }
        if ($term = $request->input('search')) {
            $base->where(fn ($q) => $q->where('cheque_number', 'like', "%{$term}%")->orWhere('party_name', 'like', "%{$term}%")->orWhere('bank_name', 'like', "%{$term}%")->orWhere('related_reference', 'like', "%{$term}%")->orWhereHas('forwardedSupplier', fn ($supplier) => $supplier->where('name', 'like', "%{$term}%")));
        }
        $totals = (clone $base)->selectRaw("COUNT(*) AS total_count, COALESCE(SUM(amount), 0) AS total_amount, COALESCE(SUM(CASE WHEN status IN ('Pending', 'Deposited') THEN amount ELSE 0 END), 0) AS hold_amount, COALESCE(SUM(CASE WHEN status = 'Cleared' THEN amount ELSE 0 END), 0) AS passed_amount, COALESCE(SUM(CASE WHEN status = 'Returned' THEN amount ELSE 0 END), 0) AS returned_amount")->first();
        $cheques = (clone $base)->orderByDesc('cheque_date')->orderByDesc('id')->paginate(25)->withQueryString();
        $title = $request->routeIs('accounts.cheques.reports') ? 'Cheque Reports' : match ($status) {
            'Pending' => 'Pending Cheques', 'Cleared' => 'Passed Cheques', 'Returned' => 'Returned Cheques',
            'Cancelled' => 'Cancelled Cheques', 'Deleted' => 'Deleted Cheques',
            default => match ($type) { 'Received' => 'Customer Cheques', 'Issued' => 'Supplier Cheques', 'Forwarded' => 'Party Cheques', default => 'All Cheques' },
        };

        return compact('base', 'cheques', 'totals', 'range', 'from', 'to', 'status', 'type', 'title');
    }

    public function create()
    {
        return view('accounts.cheque-form', ['cheque' => new Cheque(['cheque_type' => 'Received', 'status' => 'Pending', 'cheque_date' => today(), 'bank_account_id' => Account::systemAccount('bank')?->id]), 'accounts' => Account::where('active', true)->get(), 'locked' => false]);
    }

    public function store(Request $request)
    {
        $values = $this->validateDetails($request);
        $state = $request->validate(['status' => 'required|in:Pending,Deposited,Cleared', 'realized_date' => 'nullable|required_if:status,Cleared|date|before_or_equal:today']);
        DB::transaction(function () use ($values, $state) {
            $cheque = Cheque::create($values + ['status' => 'Pending', 'created_by' => auth()->id(), 'updated_by' => auth()->id()]);
            app(SettlementService::class)->resolve($cheque, $state['status'], $state['realized_date'] ?? null);
        });

        return to_route('accounts.cheques.index')->with('success', 'Cheque recorded successfully.');
    }

    public function show(Cheque $cheque)
    {
        $cheque->load(['bankAccount', 'creator', 'forwardedSupplier', 'payments.invoice', 'supplierPayments.purchase', 'jobAdvances.jobCard']);
        $history = AuditLog::where('auditable_type', $cheque->getMorphClass())->where('auditable_id', $cheque->id)->latest('id')->get();
        $users = User::whereIn('id', $history->pluck('user_id'))->pluck('name', 'id');

        return view('accounts.cheque-show', compact('cheque', 'history', 'users'));
    }

    public function edit(Cheque $cheque)
    {
        return view('accounts.cheque-form', ['cheque' => $cheque, 'accounts' => Account::where(fn ($q) => $q->where('active', true)->orWhere('id', $cheque->bank_account_id))->get(), 'locked' => $this->financialDetailsLocked($cheque)]);
    }

    private function financialDetailsLocked(Cheque $cheque): bool
    {
        return ! in_array($cheque->status, ['Pending', 'Deposited']) || $cheque->payments()->exists() || $cheque->supplierPayments()->exists() || $cheque->jobAdvances()->exists();
    }

    public function update(Request $request, Cheque $cheque)
    {
        $values = $this->validateDetails($request, $cheque);
        DB::transaction(function () use ($cheque, $values) {
            $cheque = Cheque::lockForUpdate()->findOrFail($cheque->id);
            if ($this->financialDetailsLocked($cheque)) {
                foreach (['amount', 'cheque_type', 'party_name', 'related_reference'] as $field) {
                    $same = $field === 'amount' ? round((float) $values[$field], 2) === round((float) $cheque->$field, 2) : ($values[$field] ?? '') === ($cheque->$field ?? '');
                    if (! $same) {
                        throw ValidationException::withMessages([$field => 'This value is linked to a payment or a settled cheque. Delete/cancel the cheque and record a replacement to change it.']);
                    }
                }
            }
            if (! in_array($cheque->status, ['Pending', 'Deposited'])) {
                foreach (['cheque_date', 'bank_account_id'] as $field) {
                    $old = $field === 'cheque_date' ? $cheque->cheque_date->toDateString() : $cheque->bank_account_id;
                    if ((string) ($values[$field] ?? '') !== (string) ($old ?? '')) {
                        throw ValidationException::withMessages([$field => 'The date and account of a settled cheque cannot be changed.']);
                    }
                }
            }
            $cheque->update($values + ['updated_by' => auth()->id()]);
            // Pending linked payments must use the cheque's selected account when it passes.
            if (in_array($cheque->status, ['Pending', 'Deposited'])) {
                $cheque->payments()->where('status', 'Hold')->get()->each(fn ($p) => $p->update(['account_id' => $cheque->bank_account_id]));
                $cheque->jobAdvances()->whereNull('payment_id')->where('status', 'Hold')->update(['account_id' => $cheque->bank_account_id ?? Account::systemAccount('bank')->id]);
                $cheque->supplierPayments()->where('status', 'Hold')->where('payment_method', 'Cheque')->get()->each(fn ($p) => $p->update(['account_id' => $cheque->bank_account_id]));
            }
        });

        return to_route('accounts.cheques.show', $cheque)->with('success', 'Cheque details updated.');
    }

    private function validateDetails(Request $request, ?Cheque $cheque = null): array
    {
        return $request->validate([
            'cheque_type' => 'required|in:Received,Issued', 'party_name' => 'required|string|max:120',
            'related_reference' => 'nullable|string|max:120', 'cheque_number' => 'required|string|max:80',
            'bank_name' => 'required|string|max:120', 'branch' => 'nullable|string|max:120',
            'cheque_date' => 'required|date_format:Y-m-d', 'amount' => 'required|numeric|gt:0|max:999999999999.99',
            'bank_account_id' => ['nullable', Rule::exists('accounts', 'id')->where('shop_type', session('shop_mode', 'workshop'))->where(fn ($q) => $q->where('active', true)->when($cheque?->bank_account_id, fn ($q, $id) => $q->orWhere('id', $id)))],
            'note' => 'nullable|string|max:1000',
        ]);
    }

    public function destroy(Request $request, Cheque $cheque)
    {
        $values = $request->validate(['reason' => 'required|string|min:5|max:500']);
        DB::transaction(function () use ($cheque, $values) {
            $cheque = Cheque::lockForUpdate()->findOrFail($cheque->id);
            if (! in_array($cheque->status, ['Returned', 'Cancelled'])) {
                app(SettlementService::class)->resolve($cheque, 'Cancelled', null, $values['reason']);
            }
            $cheque->refresh()->update(['status_reason' => $values['reason'], 'updated_by' => auth()->id()]);
            $cheque->delete();
        });

        return to_route('accounts.cheques.index')->with('success', 'Cheque deleted. Linked dues and ledger entries have been updated; history is kept in Deleted Cheques.');
    }

    public function updateStatus(Request $request, Cheque $cheque)
    {
        $values = $request->validate(['status' => 'required|in:Pending,Deposited,Cleared,Returned,Cancelled', 'realized_date' => 'nullable|required_if:status,Cleared|date|before_or_equal:today', 'reason' => 'nullable|required_if:status,Returned|required_if:status,Cancelled|string|min:5|max:500']);
        app(SettlementService::class)->resolve($cheque, $values['status'], $values['realized_date'] ?? null, $values['reason'] ?? null);

        return back()->with('success', 'Cheque status, dues and bank ledger updated.');
    }

    public function printReport(Request $request)
    {
        $data = $this->registerData($request);
        $data['cheques'] = $data['base']->orderBy('cheque_date')->orderBy('id')->get();

        return view('accounts.cheque-report', $data);
    }

    public function export(Request $request)
    {
        $data = $this->registerData($request);
        return response()->streamDownload(function () use ($data) {
            $output = fopen('php://output', 'w');
            fputcsv($output, ['Cheque number', 'Type', 'Party', 'Forwarded to supplier', 'Bank', 'Branch', 'Cheque date', 'Amount', 'Status', 'Passed date', 'Account', 'Reference', 'Reason', 'Note', 'Deleted at'], ',', '"', '');
            foreach ($data['base']->orderBy('id')->lazyById(250) as $cheque) {
                $row = [$cheque->cheque_number, $cheque->cheque_type, $cheque->party_name, $cheque->forwardedSupplier?->name, $cheque->bank_name, $cheque->branch, $cheque->cheque_date->toDateString(), $cheque->amount, $cheque->trashed() ? 'Deleted' : ($cheque->status === 'Cleared' ? 'Passed' : $cheque->status), $cheque->realized_date?->toDateString(), $cheque->bankAccount?->name, $cheque->related_reference, $cheque->status_reason, $cheque->note, $cheque->deleted_at];
                // Keep spreadsheet software from interpreting user-entered text as formulas.
                fputcsv($output, array_map(fn ($cell) => is_string($cell) && preg_match('/^[\s]*[=+@-]/', $cell) ? "'".$cell : $cell, $row), ',', '"', '');
            }
            fclose($output);
        }, 'cheque-report-'.today()->toDateString().'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
