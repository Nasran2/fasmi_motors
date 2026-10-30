<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\CashierSession;
use App\Models\DailyClosing;
use App\Models\Technician;
use App\Services\AccountService;
use App\Services\AccountTransactionSourceService;
use App\Services\CashierService;
use App\Services\CollectionReportService;
use App\Services\DrawerCashierService;
use App\Services\OutgoingFundingService;
use App\Services\WashingCommissionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CashierController extends Controller
{
    public function movements(Request $request, AccountTransactionSourceService $sources)
    {
        $values = $request->validate([
            'date' => 'required|date_format:Y-m-d|before_or_equal:today',
            'account' => 'required|in:'.implode(',', array_keys(CashierService::ACCOUNTS)),
            'side' => 'required|in:credit,debit',
            'page' => 'sometimes|integer|min:1',
        ]);
        $date = $values['date'];
        $side = $values['side'];
        $label = CashierService::ACCOUNTS[$values['account']];
        $query = AccountTransaction::posted()->where('account_id', Account::systemAccount($values['account'])->id)
            ->whereDate('transaction_date', $date)
            ->where(fn ($query) => $query->whereNull('category')->orWhereNotIn('category', ['cashier_opening', 'cashier_account_opening']))
            ->where($side, '>', 0);
        $total = (float) (clone $query)->sum($side);
        $rows = $query->orderByDesc('transaction_date')->orderByDesc('id')->paginate(25)->withQueryString();
        $details = $rows->getCollection()->mapWithKeys(fn ($row) => [$row->id => $sources->details($row, $request->user())]);

        return view('cashier.movements-popup', compact('date', 'side', 'label', 'rows', 'details', 'total'));
    }

    public function index(Request $request, CashierService $service, AccountService $accounts)
    {
        $request->validate(['date' => 'nullable|date_format:Y-m-d|before_or_equal:today', 'method' => 'nullable|in:all,cash,bank']);
        $active = CashierSession::active();
        $date = $request->input('date', $active?->session_date->toDateString() ?? today()->toDateString());
        $session = CashierSession::whereDate('session_date', $date)->first();
        $summary = $service->summary($date);
        $balanceVersion = $service->fingerprint($summary);
        $transactions = $summary['collections']->where('channel', 'cash');
        $suggestedOpening = $service->suggestedOpening();
        $events = $session?->events()->with('washingPerson', 'creator')->latest('id')->get() ?? collect();
        $canCorrectOpening = $service->canCorrectOpening($session);
        $canRecordOpening = ! $session && $date < today()->toDateString();
        $closing = DailyClosing::whereDate('closing_date', $date)->first();
        $history = CashierSession::orderByDesc('session_date')->paginate(15)->withQueryString();

        return view('cashier.index', compact('active', 'date', 'session', 'summary', 'balanceVersion', 'transactions', 'suggestedOpening', 'history', 'events', 'canCorrectOpening', 'canRecordOpening', 'closing'));
    }

    public function openingPopup(Request $request, CashierService $service, WashingCommissionService $washing)
    {
        $request->validate(['date' => 'nullable|date_format:Y-m-d|before_or_equal:today', 'edit' => 'nullable|boolean']);
        $active = CashierSession::active();
        $editing = $request->boolean('edit');
        $openingDate = $editing ? $request->input('date', today()->toDateString()) : today()->toDateString();
        $editingSession = $editing ? CashierSession::whereDate('session_date', $openingDate)->first() : null;
        $recording = $editing && ! $editingSession && $openingDate < today()->toDateString();
        if ($editing && ! $recording && ! $service->canCorrectOpening($editingSession)) {
            return response('Choose a saved cashier opening dated today or earlier.', 409);
        }
        if ($active && ! $editing) {
            return response('A cashier is already open. Review and close it before opening again.', 409);
        }
        $todaySession = CashierSession::whereDate('session_date', today())->first();
        $summary = $service->summary($openingDate);
        $openingPlan = $editing ? null : app(DrawerCashierService::class)->openingPlan($openingDate, $summary, $todaySession);
        $openingBalances = $editing ? app(DrawerCashierService::class)->accountDefaults($summary, false) : $openingPlan['accounts'];
        $suggestedOpening = $editing ? (float) ($editingSession?->drawer_opening_balance ?? $editingSession?->opening_balance ?? $summary['cash_opening']) : $openingPlan['cash'];
        $balanceVersion = $service->fingerprint($summary);
        $canRecalculateToday = $editing && $editingSession?->status === 'open' && $editingSession->session_date->isToday();
        $washingPerson = $todaySession?->washing_person_id;
        $defaultAmount = $washing->defaultAmount();
        $technicians = Technician::where('active', true)->orderBy('name')->get();

        return view('cashier.opening-popup', compact('suggestedOpening', 'washingPerson', 'defaultAmount', 'technicians', 'openingBalances', 'balanceVersion', 'editing', 'recording', 'editingSession', 'openingDate', 'active', 'canRecalculateToday', 'summary', 'openingPlan'));
    }

    public function collections(Request $request)
    {
        $values = $request->validate(['from' => 'nullable|date_format:Y-m-d|before_or_equal:today',
            'to' => 'nullable|date_format:Y-m-d|after_or_equal:from|before_or_equal:today',
            'method' => 'nullable|in:all,cash,bank,bank_transfer', 'search' => 'nullable|string|max:200']);
        $from = $values['from'] ?? today()->toDateString();
        $to = $values['to'] ?? $from;
        $method = $values['method'] ?? 'all';
        $all = app(CollectionReportService::class)->rows($from, $to);
        $rows = $all->filter(fn ($row) => match ($method) {
            'cash' => $row['channel'] === 'cash', 'bank' => $row['channel'] === 'bank',
            'bank_transfer' => $row['channel'] === 'bank' && $row['method'] === 'Bank Transfer', default => true,
        })->filter(fn ($row) => empty($values['search']) || str_contains(mb_strtolower(implode(' ', [$row['number'], $row['invoice'], $row['customer'], $row['description']])), mb_strtolower($values['search'])));
        $totals = ['cash' => round($all->where('channel', 'cash')->sum('amount'), 2),
            'bank' => round($all->where('channel', 'bank')->sum('amount'), 2), 'all' => round($all->sum('amount'), 2),
            'filtered' => round($rows->sum('amount'), 2)];

        return view('cashier.collections', compact('from', 'to', 'method', 'rows', 'totals'));
    }

    public function open(Request $request, CashierService $service)
    {
        $separateCash = $request->boolean('separate_cash_balance') || CashierSession::whereDate('session_date', today())->whereNotNull('drawer_opening_balance')->exists();
        $values = $request->validate([
            'opening_balance' => 'required|numeric|decimal:0,2|between:0,999999999999.99',
            'opening_reason' => 'nullable|string|max:1000',
            'save_washing_default' => 'nullable|boolean',
        ] + $this->openingAllocationRules($separateCash) + WashingCommissionService::rules());
        $service->open((float) $values['opening_balance'], $values['opening_reason'] ?? null, array_intersect_key($values, array_flip(['washing_person_id', 'washing_commission_amount'])), $request->boolean('save_washing_default'), $values['opening_balances'] ?? null, $values['balance_version'] ?? null, $separateCash);

        if ($request->expectsJson()) {
            return response()->json(['success' => true, 'redirect' => route('cashier.index')]);
        }

        return redirect()->route('cashier.index')->with('success', 'Cashier opened. Job cards, quick jobs and parts sales are now available.');
    }

    private function openingAllocationRules(bool $separateCash = false): array
    {
        $range = $separateCash ? 'between:-999999999999.99,999999999999.99' : 'between:0,999999999999.99';

        return [
            'opening_balances' => 'sometimes|array:'.($separateCash ? 'main,spare_parts,washing,bank' : 'main,spare_parts,washing'),
            'opening_balances.main' => 'required_with:opening_balances|numeric|decimal:0,2|'.$range,
            'opening_balances.spare_parts' => 'required_with:opening_balances|numeric|decimal:0,2|'.$range,
            'opening_balances.washing' => 'sometimes|required|numeric|decimal:0,2|'.$range,
            'opening_balances.bank' => 'sometimes|required|numeric|decimal:0,2|'.$range,
            'balance_version' => 'sometimes|required|string|size:64',
            'separate_cash_balance' => 'nullable|boolean',
        ];
    }

    public function correctOpening(Request $request, CashierSession $cashier, CashierService $service)
    {
        $separateCash = $request->boolean('separate_cash_balance') || $cashier->drawer_opening_balance !== null;
        $values = $request->validate([
            'opening_balance' => 'required|numeric|decimal:0,2|between:0,999999999999.99',
            'opening_reason' => 'required|string|max:1000',
            'recalculate_today' => 'nullable|boolean',
            'opening_balances' => 'required|array:'.($separateCash ? 'main,spare_parts,washing,bank' : 'main,spare_parts,washing'),
            'balance_version' => 'required|string|size:64',
        ] + $this->openingAllocationRules($separateCash));
        $service->correctOpening($cashier, (float) $values['opening_balance'], $values['opening_balances'], $values['opening_reason'], $values['balance_version'], $request->boolean('recalculate_today'), $separateCash);
        if ($request->expectsJson()) {
            return response()->json(['success' => true, 'redirect' => route('cashier.index', ['date' => $cashier->session_date->toDateString()])]);
        }

        return redirect()->route('cashier.index', ['date' => $cashier->session_date->toDateString()])->with('success', 'Opening balances corrected. The account changes are recorded in cashier history.');
    }

    public function recordHistoricalOpening(Request $request, CashierService $service)
    {
        $values = $request->validate([
            'date' => 'required|date_format:Y-m-d|before:today',
            'opening_balance' => 'required|numeric|decimal:0,2|between:0,999999999999.99',
            'opening_reason' => 'required|string|max:1000',
            'opening_balances' => 'required|array:'.($request->boolean('separate_cash_balance') ? 'main,spare_parts,washing,bank' : 'main,spare_parts,washing'),
            'balance_version' => 'required|string|size:64',
        ] + $this->openingAllocationRules($request->boolean('separate_cash_balance')));
        $service->recordHistoricalOpening($values['date'], (float) $values['opening_balance'], $values['opening_balances'], $values['opening_reason'], $values['balance_version'], $request->boolean('separate_cash_balance'));
        $redirect = route('cashier.index', ['date' => $values['date']]);
        if ($request->expectsJson()) {
            return response()->json(['success' => true, 'redirect' => $redirect]);
        }

        return redirect($redirect)->with('success', 'Historical opening saved with its reason. Recorded cash counts are preserved.');
    }

    public function close(Request $request, CashierSession $cashier, CashierService $service)
    {
        $values = $request->validate([
            'owner_withdrawal' => 'required|numeric|decimal:0,2|min:0|max:999999999999.99',
            'owner_name' => 'nullable|string|max:100',
            'closing_balance' => 'required|numeric|decimal:0,2|between:0,999999999999.99',
            'separate_cash_balance' => 'nullable|boolean',
            'account_additions' => 'sometimes|array:main,spare_parts',
            'account_additions.main' => 'required_with:account_additions|numeric|decimal:0,2|between:0,999999999999.99',
            'account_additions.spare_parts' => 'required_with:account_additions|numeric|decimal:0,2|between:0,999999999999.99',
            'balances' => 'sometimes|array:main,spare_parts,bank',
            'balances.main' => 'required_with:balances|numeric|decimal:0,2|between:-999999999999.99,999999999999.99',
            'balances.spare_parts' => 'required_with:balances|numeric|decimal:0,2|between:-999999999999.99,999999999999.99',
            'balances.bank' => 'sometimes|required|numeric|decimal:0,2|between:-999999999999.99,999999999999.99',
            'difference_reason' => 'nullable|string|max:1000', 'balance_version' => 'required|string|size:64',
        ] + OutgoingFundingService::rules());
        $service->close($cashier, $values);

        return redirect()->route('cashier.index', ['date' => $cashier->session_date->toDateString()])->with('success', 'Cashier closed. Cash withdrawal and verified cash-in-hand balances have been saved.');
    }

    public function reopen(Request $request, CashierSession $cashier, CashierService $service)
    {
        $values = $request->validate(['reason' => 'required|string|min:5|max:1000']);
        Account::ensureSystemAccounts();
        DB::transaction(function () use ($cashier, $service, $values) {
            $service->lock();
            $cashier = CashierSession::lockForUpdate()->findOrFail($cashier->id);
            if (! $cashier->session_date->isToday() || $cashier->status !== 'closed' || CashierSession::active()) {
                throw ValidationException::withMessages(['cashier' => 'Only today’s closed cashier can be reopened, with no other open session.']);
            }
            $cashier->update(['status' => 'open', 'reopen_reason' => $values['reason']]);
            $cashier->events()->create(['action' => 'reopened', 'cash_amount' => $service->suggestedOpening(),
                'washing_person_id' => $cashier->washing_person_id, 'washing_commission_amount' => $cashier->washing_commission_amount,
                'details' => ['reason' => $values['reason']], 'created_by' => auth()->id()]);
            DailyClosing::whereDate('closing_date', today())->update(['status' => 'reopened', 'reopen_reason' => $values['reason'], 'reopened_by' => auth()->id(), 'reopened_at' => now()]);
        });

        return redirect()->route('cashier.index')->with('success', 'Cashier reopened. Previous withdrawals remain recorded.');
    }
}
