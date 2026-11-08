<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\CashierSession;
use App\Models\DailyClosing;
use App\Models\Technician;
use App\Services\AccountService;
use App\Services\AccountTransactionSourceService;
use App\Services\CashierAccountBalanceService;
use App\Services\CashierCardBreakdownService;
use App\Services\CashierClosingHistoryService;
use App\Services\CashierService;
use App\Services\CollectionReportService;
use App\Services\DrawerCashierService;
use App\Services\OutgoingFundingService;
use App\Services\WashingCommissionService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CashierController extends Controller
{
    public function cardDetails(Request $request, CashierService $cashier, CashierCardBreakdownService $breakdowns)
    {
        $values = $request->validate([
            'date' => 'required|date_format:Y-m-d|before_or_equal:today',
            'card' => 'required|in:opening,received,paid,balance,washing',
            'page' => 'sometimes|integer|min:1',
        ]);
        $date = $values['date'];
        $card = $values['card'];
        $session = CashierSession::whereDate('session_date', $date)->first();
        $summary = $cashier->summary($date);
        $labels = ['opening' => 'Opening cash in hand', 'received' => 'Cash collected today', 'paid' => 'Cash paid out today', 'balance' => 'Cash in hand now'];
        $totals = ['opening' => $summary['cash_opening'], 'received' => $summary['cash_received'], 'paid' => $summary['cash_paid'], 'balance' => $summary['cash_in_hand']];
        $labels['washing'] = 'Washing received · information only';
        $totals['washing'] = $summary['washing_received'];
        $items = $breakdowns->breakdown($date, $summary, $session, $request->user())[$card];
        $page = $request->integer('page', 1);
        $rows = new LengthAwarePaginator($items->forPage($page, 25)->values(), $items->count(), 25, $page,
            ['path' => $request->url(), 'query' => $request->query()]);
        $events = $card === 'opening' ? ($session?->events()->with('creator')->whereIn('action', ['opened', 'reopened', 'opening_recorded', 'opening_corrected'])->orderBy('id')->get() ?? collect()) : collect();

        return view('cashier.card-details-popup', compact('date', 'card', 'labels', 'totals', 'summary', 'rows', 'events'));
    }

    public function movements(Request $request, AccountTransactionSourceService $sources, AccountService $accounts)
    {
        $values = $request->validate([
            'date' => 'required|date_format:Y-m-d|before_or_equal:today',
            'account' => 'required|in:'.implode(',', array_keys(CashierService::ACCOUNTS)),
            'side' => 'required|in:credit,debit,adjustment',
            'page' => 'sometimes|integer|min:1',
        ]);
        $date = $values['date'];
        $side = $values['side'];
        $label = CashierService::ACCOUNTS[$values['account']];
        $query = AccountTransaction::posted()->where('account_id', Account::systemAccount($values['account'])->id)
            ->whereDate('transaction_date', $date)
            ->where(fn ($query) => $query->whereNull('category')->orWhereNotIn('category', ['cashier_opening', 'cashier_account_opening']));
        $reconciliation = 0;
        $recordedOpening = null;
        $ledgerOpening = null;
        $postedAdjustments = 0;
        if ($side === 'adjustment') {
            $query->balanceAdjustments();
            $postedAdjustments = round((clone $query)->sum('credit') - (clone $query)->sum('debit'), 2);
            $total = $postedAdjustments;
            $recordedOpening = CashierSession::whereDate('session_date', $date)->first()?->account_opening_balances[$values['account']] ?? null;
            if ($recordedOpening !== null) {
                $openingCorrections = AccountTransaction::posted()
                    ->where('account_id', Account::systemAccount($values['account'])->id)
                    ->whereDate('transaction_date', $date)
                    ->whereIn('category', ['cashier_opening', 'cashier_account_opening'])->get();
                $ledgerOpening = round($accounts->ledgerOpeningBalance($values['account'], Carbon::parse($date)->startOfDay())
                    + $openingCorrections->sum('credit') - $openingCorrections->sum('debit'), 2);
                $reconciliation = round($ledgerOpening - (float) $recordedOpening, 2);
                $total = round($total + $reconciliation, 2);
            }
        } else {
            $query->balanceAdjustments(false)->where($side, '>', 0);
            $total = (float) (clone $query)->sum($side);
        }
        $rows = $query->orderByDesc('transaction_date')->orderByDesc('id')->paginate(25)->withQueryString();
        $details = $rows->getCollection()->mapWithKeys(fn ($row) => [$row->id => $sources->details($row, $request->user())]);

        return view('cashier.movements-popup', compact('date', 'side', 'label', 'rows', 'details', 'total', 'reconciliation', 'recordedOpening', 'ledgerOpening', 'postedAdjustments'));
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
            return $this->closingPopup($request, $active, $service, app(AccountTransactionSourceService::class));
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

    public function closingPopup(Request $request, CashierSession $cashier, CashierService $service, AccountTransactionSourceService $sources)
    {
        $request->validate(['event' => 'nullable|integer|min:1', 'page' => 'nullable|integer|min:1']);
        abort_if($cashier->session_date->isFuture(), 404);
        $session = $cashier;
        $date = $session->session_date->toDateString();
        $isToday = $session->session_date->isToday();
        $event = $request->filled('event') ? $session->events()->with('creator', 'washingPerson')
            ->whereIn('action', ['closed', 'closing_corrected'])->findOrFail($request->integer('event')) : null;
        $liveClosing = $session->status === 'open' && ! $event;
        $summary = $liveClosing ? $service->summary($date) : (($event ? ($event->details['closing_snapshot'] ?? []) : ($session->closing_summary ?? [])));
        $balanceVersion = $liveClosing ? $service->fingerprint($summary) : null;
        $expectedBalances = $liveClosing ? collect($summary['cash_accounts'])->map(fn ($account) => $account['closing'])->all() : [];
        if ($liveClosing) {
            foreach (['main', 'spare_parts', 'bank', 'washing'] as $type) {
                $expectedBalances[$type] = $summary['accounts'][$type]['closing'];
            }
        }
        $closing = DailyClosing::whereDate('closing_date', $date)->first();
        $savedBalances = $event ? ($event->details['cash_balances'] ?? $event->details['balances'] ?? []) : ($session->actual_balances ?? []);
        $savedBalances['bank'] = $event ? ($event->details['bank_balance'] ?? $savedBalances['bank'] ?? $summary['closing_bank_balance'] ?? null)
            : ($savedBalances['bank'] ?? $summary['closing_bank_balance'] ?? $closing?->bank_actual_balance);
        $savedCash = $event?->cash_amount ?? $session->drawer_closing_balance ?? $closing?->cash_actual_balance
            ?? ($session->cash_balances ? array_sum($session->cash_balances) : null);
        $closingVersion = app(CashierClosingHistoryService::class)->version($session);
        $canEdit = ! $event && $session->status === 'closed' && $request->user()->canAccess('account.daily_closing');
        $audit = $session->events()->with('creator', 'washingPerson')->whereIn('action', ['closed', 'closing_corrected', 'account_balance_corrected'])->latest('id')->get();
        $query = AccountTransaction::posted()->whereIn('account_id', collect(CashierService::ACCOUNTS)->map(fn ($label, $type) => Account::systemAccount($type)->id))->whereDate('transaction_date', $date);
        if (isset($summary['closing_transaction_ids'])) {
            $query->whereIn('id', $summary['closing_transaction_ids']);
        } elseif (! $liveClosing && ($event?->created_at ?? $session->closed_at)) {
            $query->where('created_at', '<=', $event?->created_at ?? $session->closed_at);
        }
        $rows = $query->with('account')->orderBy('transaction_date')->orderBy('id')->paginate(50)->withQueryString();
        $details = $rows->getCollection()->mapWithKeys(fn ($row) => [$row->id => $sources->details($row, $request->user())]);

        return view('cashier.closing-popup', compact('session', 'date', 'isToday', 'event', 'liveClosing', 'summary', 'balanceVersion', 'expectedBalances', 'closing', 'savedBalances', 'savedCash', 'closingVersion', 'canEdit', 'audit', 'rows', 'details'));
    }

    private function accountTotalRules(): array
    {
        return [
            'account' => 'required|in:main,spare_parts',
            'account_addition' => 'required|numeric|decimal:0,2|between:0,999999999999.99',
            'owner_withdrawal' => 'required|numeric|decimal:0,2|between:0,999999999999.99',
            'balance_version' => 'required|string|size:64',
        ] + OutgoingFundingService::rules();
    }

    public function accountTotalPopup(Request $request, CashierSession $cashier, CashierAccountBalanceService $service)
    {
        $values = $request->validate($this->accountTotalRules());
        $preview = $service->preview($cashier, $values);

        return view('cashier.account-total-popup', compact('cashier', 'values', 'preview'));
    }

    public function correctAccountTotal(Request $request, CashierSession $cashier, CashierAccountBalanceService $service)
    {
        $values = $request->validate($this->accountTotalRules() + [
            'total_balance' => 'required|numeric|decimal:0,2|between:-999999999999.99,999999999999.99',
            'reason' => 'required|string|min:3|max:1000',
        ]);
        $result = $service->correct($cashier, $values);
        if ($request->expectsJson()) {
            return response()->json($result);
        }

        return redirect()->route('cashier.index', ['date' => $cashier->session_date->toDateString()])
            ->with('success', 'Account total corrected. The reason is recorded and the balance carries forward.');
    }

    public function correctClosing(Request $request, CashierSession $cashier, CashierClosingHistoryService $service)
    {
        $values = $request->validate([
            'closing_version' => 'required|string|size:64', 'reason' => 'required|string|min:3|max:1000',
            'closing_balance' => 'required|numeric|decimal:0,2|between:0,999999999999.99',
            'balances' => 'required|array:main,spare_parts,bank,washing',
            'balances.main' => 'required|numeric|decimal:0,2|between:-999999999999.99,999999999999.99',
            'balances.spare_parts' => 'required|numeric|decimal:0,2|between:-999999999999.99,999999999999.99',
            'balances.bank' => 'required|numeric|decimal:0,2|between:-999999999999.99,999999999999.99',
            'balances.washing' => 'required|numeric|decimal:0,2|between:-999999999999.99,999999999999.99',
            'account_additions' => 'sometimes|array:main,spare_parts',
            'account_additions.main' => 'required_with:account_additions|numeric|decimal:0,2|between:0,999999999999.99',
            'account_additions.spare_parts' => 'required_with:account_additions|numeric|decimal:0,2|between:0,999999999999.99',
        ]);
        $service->correct($cashier, $values);
        $redirect = route('cashier.index', ['date' => $cashier->session_date->toDateString()]);
        if ($request->expectsJson()) {
            return response()->json(['success' => true, 'redirect' => $redirect]);
        }

        return redirect($redirect)->with('success', 'Closing corrected with its reason. Later recorded opening and closing counts are preserved.');
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
        })->filter(fn ($row) => empty($values['search']) || str_contains(mb_strtolower(implode(' ', [$row['number'], $row['invoice'], $row['customer'], $row['description'], implode(' ', $row['bikes'] ?? [])])), mb_strtolower($values['search'])));
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
        $closedPastDate = ! $cashier->session_date->isToday();
        $redirect = $closedPastDate ? route('cashier.index') : route('cashier.index', ['date' => $cashier->session_date->toDateString()]);
        $message = $closedPastDate
            ? 'Cashier for ' . $cashier->session_date->format('d M Y') . ' has been closed. You can now open today’s cashier to begin operations.'
            : 'Cashier closed. Cash withdrawal and verified cash-in-hand balances have been saved.';

        if ($request->expectsJson()) {
            return response()->json(['success' => true, 'redirect' => $redirect]);
        }

        return redirect($redirect)->with('success', $message);
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
