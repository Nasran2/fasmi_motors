<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\CashierSession;
use App\Models\DailyClosing;
use App\Models\Technician;
use App\Services\AccountService;
use App\Services\CashierService;
use App\Services\CollectionReportService;
use App\Services\WashingCommissionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CashierController extends Controller
{
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
        $history = CashierSession::orderByDesc('session_date')->paginate(15)->withQueryString();

        return view('cashier.index', compact('active', 'date', 'session', 'summary', 'balanceVersion', 'transactions', 'suggestedOpening', 'history', 'events'));
    }

    public function openingPopup(Request $request, CashierService $service, WashingCommissionService $washing)
    {
        $active = CashierSession::active();
        if ($active) {
            return response('A cashier is already open. Review and close it before opening again.', 409);
        }
        $todaySession = CashierSession::whereDate('session_date', today())->first();
        $suggestedOpening = $service->suggestedOpening();
        $washingPerson = $todaySession?->washing_person_id;
        $defaultPercentage = $washing->defaultPercentage();
        $technicians = Technician::where('active', true)->orderBy('name')->get();

        return view('cashier.opening-popup', compact('suggestedOpening', 'washingPerson', 'defaultPercentage', 'technicians'));
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
        $values = $request->validate([
            'opening_balance' => 'required|numeric|decimal:0,2|between:0,999999999999.99',
            'opening_reason' => 'nullable|string|max:1000',
            'save_washing_default' => 'nullable|boolean',
        ] + WashingCommissionService::rules());
        $service->open((float) $values['opening_balance'], $values['opening_reason'] ?? null, array_intersect_key($values, array_flip(['washing_person_id', 'washing_commission_percentage'])), $request->boolean('save_washing_default'));

        if ($request->expectsJson()) {
            return response()->json(['success' => true, 'redirect' => route('cashier.index')]);
        }

        return redirect()->route('cashier.index')->with('success', 'Cashier opened. Job cards, quick jobs and parts sales are now available.');
    }

    public function close(Request $request, CashierSession $cashier, CashierService $service)
    {
        $values = $request->validate([
            'owner_withdrawal' => 'required|numeric|decimal:0,2|min:0|max:999999999999.99',
            'owner_name' => 'nullable|string|max:100', 'balances' => 'required|array:main,washing,spare_parts',
            'balances.main' => 'required|numeric|decimal:0,2|between:-999999999999.99,999999999999.99',
            'balances.washing' => 'required|numeric|decimal:0,2|between:-999999999999.99,999999999999.99',
            'balances.spare_parts' => 'required|numeric|decimal:0,2|between:-999999999999.99,999999999999.99',
            'difference_reason' => 'nullable|string|max:1000', 'balance_version' => 'required|string|size:64',
        ]);
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
                'washing_person_id' => $cashier->washing_person_id, 'washing_commission_percentage' => $cashier->washing_commission_percentage,
                'details' => ['reason' => $values['reason']], 'created_by' => auth()->id()]);
            DailyClosing::whereDate('closing_date', today())->update(['status' => 'reopened', 'reopen_reason' => $values['reason'], 'reopened_by' => auth()->id(), 'reopened_at' => now()]);
        });

        return redirect()->route('cashier.index')->with('success', 'Cashier reopened. Previous withdrawals remain recorded.');
    }
}
