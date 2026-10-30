<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\JobCard;
use App\Models\Technician;
use App\Services\CommissionSettlementService;
use App\Services\OutgoingFundingService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CommissionController extends Controller
{
    private function period(Request $request): array
    {
        $data = $request->validate(['period' => ['nullable', Rule::in(['day', 'month', 'custom', 'all'])],
            'date' => ['nullable', 'date_format:Y-m-d'], 'month' => ['nullable', 'date_format:Y-m'],
            'from' => ['nullable', 'required_if:period,custom', 'date_format:Y-m-d'],
            'to' => ['nullable', 'required_if:period,custom', 'date_format:Y-m-d', 'after_or_equal:from'],
            'technician' => ['nullable', 'integer', 'exists:technicians,id']]);
        $type = $data['period'] ?? 'month';
        $day = Carbon::parse($data['date'] ?? today())->startOfDay();
        $month = Carbon::parse(($data['month'] ?? today()->format('Y-m')).'-01');

        return match ($type) {
            'day' => ['from' => $day->toDateString(), 'to' => $day->toDateString(), 'label' => $day->format('d M Y')],
            'custom' => ['from' => $data['from'], 'to' => $data['to'], 'label' => $data['from'].' to '.$data['to']],
            'all' => ['from' => null, 'to' => null, 'label' => 'All dates'],
            default => ['from' => $month->copy()->startOfMonth()->toDateString(), 'to' => $month->copy()->endOfMonth()->toDateString(), 'label' => $month->format('F Y')],
        };
    }

    public function index(Request $request, CommissionSettlementService $service)
    {
        $period = $this->period($request);
        $rows = $service->rows($request->integer('technician') ?: null, $period);
        $groups = $rows->groupBy('technician_id')->map(fn ($items) => ['technician' => $items->first()->technician, 'totals' => $service->totals($items)]);
        $totals = $service->totals($rows);

        return view('technicians.commissions', ['groups' => $groups, 'period' => $period, 'totals' => $totals,
            'technicians' => Technician::orderBy('name')->get(), 'jobs' => JobCard::with('invoice')->whereNotNull('main_technician_id')->latest()->limit(100)->get()]);
    }

    public function show(Request $request, Technician $technician, CommissionSettlementService $service)
    {
        $data = $this->details($request, $technician, $service);

        return view($request->ajax() ? 'technicians.partials.commission-detail' : 'technicians.commission-show', $data);
    }

    public function slip(Request $request, Technician $technician, CommissionSettlementService $service)
    {
        return view('technicians.commission-slip', $this->details($request, $technician, $service));
    }

    private function details(Request $request, Technician $technician, CommissionSettlementService $service): array
    {
        $period = $this->period($request);
        $rows = $service->rows($technician->id, $period);
        $payments = $rows->flatMap(fn ($row) => $row->paymentItems)->groupBy('commission_payment_id')
            ->map(fn ($items) => ['payment' => $items->first()->payment, 'amount' => (float) $items->sum('amount')]);

        return ['technician' => $technician, 'period' => $period, 'rows' => $rows, 'payments' => $payments,
            'totals' => $service->totals($rows), 'balanceVersion' => $service->version($rows, $period),
            'accounts' => Account::where('active', true)->orderBy('name')->get()];
    }

    public function deduct(Request $request, Technician $technician, CommissionSettlementService $service)
    {
        return $this->settle($request, $technician, $service, false);
    }

    public function pay(Request $request, Technician $technician, CommissionSettlementService $service)
    {
        return $this->settle($request, $technician, $service, true);
    }

    private function settle(Request $request, Technician $technician, CommissionSettlementService $service, bool $payment)
    {
        $period = $this->period($request);
        $rules = ['amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2', 'max:999999999999.99'], 'balance_version' => ['required', 'string', 'size:64']];
        $rules += $payment ? ['account_id' => ['required', Rule::exists('accounts', 'id')->where('active', true)->where('shop_type', session('shop_mode', 'workshop'))],
            'payment_method' => ['required', Rule::in(['Cash', 'Bank Transfer', 'Card', 'Online', 'Other'])],
            'payment_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'], 'note' => ['nullable', 'string', 'max:1000']]
            : ['description' => ['required', 'string', 'min:3', 'max:500']];
        $data = $request->validate($rules + ($payment ? OutgoingFundingService::rules() : []));
        $payout = $service->settle($technician, $period, $data, $payment);
        $query = $request->only(['period', 'date', 'month', 'from', 'to', 'technician']);

        return redirect()->route('technicians.commissions', $query)->with('commission_open', $technician->id)
            ->with('success', $payout ? "Commission payment {$payout->number} recorded." : 'Commission deduction recorded with its description.');
    }
}
