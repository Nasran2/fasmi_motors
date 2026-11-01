<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\AuditLog;
use App\Models\OwnerCapital;
use App\Services\OwnerCapitalService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OwnerCapitalController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate(['from' => 'nullable|date_format:Y-m-d', 'to' => ['nullable', 'date_format:Y-m-d', Rule::when($request->filled('from'), 'after_or_equal:from')],
            'q' => 'nullable|string|max:150', 'account_id' => 'nullable|integer', 'status' => 'nullable|in:active,deleted,all']);
        $query = OwnerCapital::query();
        if (($filters['status'] ?? 'active') !== 'active') {
            $query->withTrashed();
            if ($filters['status'] === 'deleted') {
                $query->onlyTrashed();
            }
        }
        $query->when($filters['from'] ?? null, fn ($q, $date) => $q->whereDate('capital_date', '>=', $date))
            ->when($filters['to'] ?? null, fn ($q, $date) => $q->whereDate('capital_date', '<=', $date))
            ->when($filters['account_id'] ?? null, fn ($q, $id) => $q->where('account_id', $id))
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->where(fn ($q) => $q->where('number', 'like', "%{$term}%")
                ->orWhere('owner_name', 'like', "%{$term}%")->orWhere('reference_number', 'like', "%{$term}%")->orWhere('note', 'like', "%{$term}%")));
        $total = (float) (clone $query)->whereNull('deleted_at')->sum('amount');
        $records = $query->with(['account', 'creator'])->latest('capital_date')->latest('id')->paginate(20)->withQueryString();
        $accounts = $this->accounts();

        return view('owner-capital.index', compact('records', 'accounts', 'total'));
    }

    public function create()
    {
        return $this->form(new OwnerCapital(['capital_date' => today(), 'account_id' => Account::systemAccount('spare_parts')->id, 'payment_method' => 'Cash']));
    }

    public function store(Request $request, OwnerCapitalService $service)
    {
        $capital = $service->save($this->validateRecord($request));

        return redirect()->route('owner-capital.show', $capital)->with('success', 'Owner capital added and account balance updated.');
    }

    public function show(OwnerCapital $capital)
    {
        $capital->load(['account', 'creator', 'editor']);
        $transactions = AccountTransaction::where('reference_type', $capital->getMorphClass())->where('reference_id', $capital->id)
            ->with('account')->orderBy('id')->get();
        $history = AuditLog::where('auditable_type', $capital->getMorphClass())->where('auditable_id', $capital->id)->latest('id')->get();

        return view('owner-capital.show', compact('capital', 'transactions', 'history'));
    }

    public function edit(OwnerCapital $capital)
    {
        return $this->form($capital);
    }

    public function update(Request $request, OwnerCapital $capital, OwnerCapitalService $service)
    {
        $capital = $service->save($this->validateRecord($request, true), $capital);

        return redirect()->route('owner-capital.show', $capital)->with('success', 'Owner capital updated and account balances corrected.');
    }

    public function destroy(Request $request, OwnerCapital $capital, OwnerCapitalService $service)
    {
        $data = $request->validate(['reason' => 'required|string|min:5|max:500', 'record_version' => 'required|string|size:64']);
        $service->delete($capital, $data['reason'], $data['record_version']);

        return redirect()->route('owner-capital.index')->with('success', 'Owner capital deleted and its account credit reversed.');
    }

    private function form(OwnerCapital $capital)
    {
        $accounts = $this->accounts();

        return view('owner-capital.form', compact('capital', 'accounts'));
    }

    private function accounts()
    {
        Account::ensureSystemAccounts();

        return Account::whereIn('code', array_map(fn ($type) => Account::systemCode($type), array_keys(OwnerCapitalService::ACCOUNTS)))
            ->where('active', true)->orderBy('id')->get();
    }

    private function validateRecord(Request $request, bool $editing = false): array
    {
        return $request->validate(['capital_date' => 'required|date_format:Y-m-d|before_or_equal:today',
            'owner_name' => 'required|string|max:150', 'account_id' => ['required', 'integer', Rule::in($this->accounts()->modelKeys())],
            'amount' => 'required|numeric|decimal:0,2|gt:0|max:999999999999.99',
            'payment_method' => 'required|in:Cash,Bank Transfer', 'reference_number' => 'nullable|string|max:100', 'note' => 'nullable|string|max:1000',
            'reason' => ($editing ? 'required' : 'nullable').'|string|min:5|max:500',
            'record_version' => ($editing ? 'required' : 'nullable').'|string|size:64']);
    }
}
