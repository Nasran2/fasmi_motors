<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\Invoice;
use App\Models\JobCard;
use App\Models\Product;
use App\Models\Technician;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class ReportController extends Controller
{
    public function index(Request $r)
    {
        $validated = $r->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $from = isset($validated['from'])
            ? Carbon::parse($validated['from'])->startOfDay()
            : now()->startOfMonth()->startOfDay();
        $to = isset($validated['to'])
            ? Carbon::parse($validated['to'])->endOfDay()
            : today()->endOfDay();

        $invoices = Invoice::whereBetween('created_at', [$from, $to]);
        $jobs = JobCard::whereBetween('created_at', [$from, $to]);

        return view('reports.index', ['from' => $from, 'to' => $to, 'sales' => (clone $invoices)->sum('total'), 'paid' => (clone $invoices)->sum('paid_amount'), 'due' => (clone $invoices)->sum('due_amount'), 'expenses' => Expense::whereBetween('expense_date', [$from, $to])->sum('amount'), 'jobsByStatus' => $jobs->selectRaw('status, count(*) total')->groupBy('status')->pluck('total', 'status'), 'lowStock' => Product::withSum('stocks', 'quantity')->get()->filter(fn ($p) => (float) $p->stocks_sum_quantity <= (float) $p->minimum_stock), 'technicians' => Technician::withCount(['jobs' => fn ($q) => $q->whereBetween('created_at', [$from, $to])])->get()]);
    }
}
