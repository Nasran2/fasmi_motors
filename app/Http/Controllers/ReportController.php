<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\Customer;
use App\Models\DailyClosing;
use App\Models\Drawing;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\JobCard;
use App\Models\JobCardPart;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\ServiceReminder;
use App\Models\SmsLog;
use App\Models\Technician;
use App\Models\TechnicianAttendance;
use App\Models\TechnicianContribution;
use App\Models\TechnicianSalarySheet;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ReportController extends Controller
{
    // ─── SHARED HELPERS ─────────────────────────────────────────

    protected function dateRange(Request $r, string $default = 'today'): array
    {
        $data = $r->validate([
            'date_range' => ['nullable', Rule::in(['all_time', 'today', 'yesterday', 'this_week', 'last_week', 'this_month', 'last_month', 'this_year', 'last_year', 'custom'])],
            'from' => ['nullable', 'required_with:to', 'date_format:Y-m-d'],
            'to' => ['nullable', 'required_with:from', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $range = ($r->filled('from') || $r->filled('to')) ? 'custom' : ($data['date_range'] ?? $default);
        $from = $data['from'] ?? null;
        $to = $data['to'] ?? null;

        return match ($range) {
            'all_time' => [Carbon::parse('2000-01-01')->startOfDay(), Carbon::now()->addYears(10)->endOfDay()],
            'today' => [today()->startOfDay(), today()->endOfDay()],
            'yesterday' => [today()->subDay()->startOfDay(), today()->subDay()->endOfDay()],
            'this_week' => [today()->startOfWeek()->startOfDay(), today()->endOfDay()],
            'last_week' => [today()->subWeek()->startOfWeek()->startOfDay(), today()->subWeek()->endOfWeek()->endOfDay()],
            'this_month' => [today()->startOfMonth()->startOfDay(), today()->endOfDay()],
            'last_month' => [today()->subMonth()->startOfMonth()->startOfDay(), today()->subMonth()->endOfMonth()->endOfDay()],
            'this_year' => [today()->startOfYear()->startOfDay(), today()->endOfDay()],
            'last_year' => [today()->subYear()->startOfYear()->startOfDay(), today()->subYear()->endOfYear()->endOfDay()],
            default => [
                $from ? Carbon::parse($from)->startOfDay() : today()->startOfDay(),
                $to ? Carbon::parse($to)->endOfDay() : today()->endOfDay(),
            ],
        };
    }

    protected function activeRange(Request $r, string $default = 'today'): string
    {
        return ($r->filled('from') || $r->filled('to')) ? 'custom' : $r->input('date_range', $default);
    }

    // ─── 1. SALES REPORT ───────────────────────────────────────

    public function index(Request $r)
    {
        [$from, $to] = $this->dateRange($r);

        $q = Invoice::where('status', '!=', 'Cancelled')->with(['customer', 'jobCard'])
            ->whereBetween('created_at', [$from, $to])
            ->when($r->invoice_type, fn ($q, $v) => $q->where('invoice_type', $v))
            ->when($r->status, fn ($q, $v) => $q->where('status', $v))
            ->when($r->payment_method, fn ($q, $v) => $q->where('payment_method', $v))
            ->when($r->search, fn ($q, $s) => $q->where(fn ($x) => $x->where('number', 'like', "%{$s}%")
                ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$s}%"))
            ));

        $rows = $q->latest()->paginate($r->has('print') ? 9999 : 25)->withQueryString();
        $totals = (clone $q)->reorder()->selectRaw('
            count(*) as total_count,
            sum(total) as total_sales,
            sum(paid_amount) as total_paid,
            sum(due_amount) as total_due,
            sum(discount) as total_discount
        ')->first();

        $byType = Invoice::where('status', '!=', 'Cancelled')->whereBetween('created_at', [$from, $to])
            ->selectRaw('invoice_type, sum(total) as amount')
            ->groupBy('invoice_type')->pluck('amount', 'invoice_type');

        $customers = Customer::orderBy('name')->get(['id', 'name']);

        return view('reports.sales', compact('rows', 'totals', 'byType', 'customers', 'from', 'to') + ['range' => $this->activeRange($r)]);
    }

    // ─── 2. JOB CARD REPORT ────────────────────────────────────

    public function jobCards(Request $r)
    {
        [$from, $to] = $this->dateRange($r);

        $rows = JobCard::with(['intake.bike.customer', 'technician', 'invoice'])
            ->whereBetween('created_at', [$from, $to])
            ->when($r->status, fn ($q, $v) => $q->where('status', $v))
            ->when($r->technician, fn ($q, $v) => $q->where('main_technician_id', $v))
            ->when($r->search, fn ($q, $s) => $q->where(fn ($x) => $x->where('number', 'like', "%{$s}%")
                ->orWhereHas('intake.bike.customer', fn ($c) => $c->where('name', 'like', "%{$s}%"))
                ->orWhereHas('intake.bike', fn ($b) => $b->where('number', 'like', "%{$s}%"))
            ))
            ->latest()->paginate($r->has('print') ? 9999 : 25)->withQueryString();

        $stats = [
            'total' => JobCard::whereBetween('created_at', [$from, $to])->count(),
            'completed' => JobCard::whereBetween('created_at', [$from, $to])->where('status', 'Completed')->count(),
            'in_progress' => JobCard::whereBetween('created_at', [$from, $to])->where('status', 'In Progress')->count(),
            'pending' => JobCard::whereBetween('created_at', [$from, $to])->where('status', 'Pending')->count(),
            'waiting_parts' => JobCard::whereBetween('created_at', [$from, $to])->where('status', 'Waiting for Parts')->count(),
            'cancelled' => JobCard::whereBetween('created_at', [$from, $to])->where('status', 'Cancelled')->count(),
            'total_income' => Invoice::where('status', '!=', 'Cancelled')->whereBetween('created_at', [$from, $to])->where('invoice_type', 'Job Card Bill')->sum('total'),
        ];

        $technicians = Technician::orderBy('name')->get(['id', 'name']);
        $statuses = ['Pending', 'In Progress', 'Waiting for Parts', 'Completed', 'Delivered', 'Cancelled'];

        return view('reports.job-cards', compact('rows', 'stats', 'technicians', 'statuses', 'from', 'to') + ['range' => $this->activeRange($r)]);
    }

    // ─── 3. SPARE PARTS SALES REPORT ──────────────────────────

    public function spareParts(Request $r)
    {
        [$from, $to] = $this->dateRange($r);

        $rows = InvoiceItem::with(['invoice.customer', 'product'])
            ->whereHas('invoice', fn ($q) => $q->whereBetween('created_at', [$from, $to])
                ->where('status', '!=', 'Cancelled')->where('invoice_type', 'Parts Sale')
            )
            ->when($r->search, fn ($q, $s) => $q->whereHas('product', fn ($p) => $p->where('name', 'like', "%{$s}%")->orWhere('code', 'like', "%{$s}%")
            ))
            ->latest()->paginate($r->has('print') ? 9999 : 25)->withQueryString();

        $stats = [
            'total_sales' => InvoiceItem::whereHas('invoice', fn ($q) => $q->whereBetween('created_at', [$from, $to])->where('status', '!=', 'Cancelled')->where('invoice_type', 'Parts Sale'))->sum(DB::raw('quantity * unit_price')),
            'items_sold' => InvoiceItem::whereHas('invoice', fn ($q) => $q->whereBetween('created_at', [$from, $to])->where('status', '!=', 'Cancelled')->where('invoice_type', 'Parts Sale'))->sum('quantity'),
            'total_cost' => InvoiceItem::whereHas('invoice', fn ($q) => $q->whereBetween('created_at', [$from, $to])->where('status', '!=', 'Cancelled')->where('invoice_type', 'Parts Sale'))->sum(DB::raw('quantity * cost_price')),
        ];
        $stats['gross_profit'] = $stats['total_sales'] - $stats['total_cost'];

        return view('reports.spare-parts', compact('rows', 'stats', 'from', 'to') + ['range' => $this->activeRange($r)]);
    }

    // ─── 4. PROFIT & LOSS ──────────────────────────────────────

    public function profitLoss(Request $r)
    {
        [$from, $to] = $this->dateRange($r);

        $serviceIncome = Invoice::where('status', '!=', 'Cancelled')->whereBetween('created_at', [$from, $to])
            ->where('invoice_type', 'Job Card Bill')->sum(DB::raw('greatest(0, total - parts_amount)'));

        $partsSales = Invoice::where('status', '!=', 'Cancelled')->whereBetween('created_at', [$from, $to])
            ->whereIn('invoice_type', ['Job Card Bill', 'Parts Sale'])->sum(DB::raw("case when invoice_type = 'Parts Sale' then total else least(total, parts_amount) end"));
        $partsCost = InvoiceItem::whereHas('invoice', fn ($q) => $q->whereBetween('created_at', [$from, $to])->where('status', '!=', 'Cancelled')->where('invoice_type', 'Parts Sale')
        )->sum(DB::raw('quantity * cost_price'));
        $partsCost += JobCardPart::whereHas('jobCard.invoice', fn ($q) => $q->whereBetween('created_at', [$from, $to])->where('status', '!=', 'Cancelled'))->sum(DB::raw('quantity * cost_price'));
        $partsProfit = $partsSales - $partsCost;

        $otherIncome = Invoice::where('status', '!=', 'Cancelled')->whereBetween('created_at', [$from, $to])
            ->whereNotIn('invoice_type', ['Job Card Bill', 'Parts Sale', 'Opening Due'])->sum('total');

        $totalIncome = $serviceIncome + $partsProfit + $otherIncome;

        $salaryExpense = TechnicianSalarySheet::where('status', '!=', 'Draft')->whereBetween('period_end', [$from, $to])->sum(DB::raw('gross_salary + epf_company + etf_company'));
        $totalExpenses = Expense::where('status', '!=', 'Cancelled')->whereBetween('expense_date', [$from, $to])->sum('amount') + $salaryExpense;
        $totalDrawings = Drawing::where('status', '!=', 'Cancelled')->whereBetween('drawing_date', [$from, $to])->sum('amount');

        $netProfit = $totalIncome - $totalExpenses;

        $expenseByCategory = Expense::where('status', '!=', 'Cancelled')->whereBetween('expense_date', [$from, $to])
            ->selectRaw('category, sum(amount) as total')
            ->groupBy('category')->get();
        if ($salaryExpense > 0) {
            $expenseByCategory->push((object) ['category' => 'Salary and employer contributions', 'total' => $salaryExpense]);
        }

        return view('reports.profit-loss', compact(
            'serviceIncome', 'partsSales', 'partsCost', 'partsProfit',
            'otherIncome', 'totalIncome', 'totalExpenses', 'totalDrawings',
            'netProfit', 'expenseByCategory', 'from', 'to'
        ) + ['range' => $this->activeRange($r)]);
    }

    // ─── 5. STOCK REPORT ───────────────────────────────────────

    public function stock(Request $r)
    {
        [$from, $to] = $this->dateRange($r, 'all_time');

        $rackOptions = Product::whereNotNull('rack_number')
            ->where('rack_number', '!=', '')
            ->distinct()
            ->pluck('rack_number')
            ->sort(SORT_NATURAL | SORT_FLAG_CASE)
            ->values();

        $q = Product::with(['category'])
            ->withSum('stocks', 'quantity')
            ->when($r->search, fn ($q, $s) => $q->where(fn ($search) => $search->where('name', 'like', "%{$s}%")->orWhere('code', 'like', "%{$s}%")))
            ->when($r->status === 'low', fn ($q) => $q->having('stocks_sum_quantity', '>', 0)->having('stocks_sum_quantity', '<=', DB::raw('products.minimum_stock')))
            ->when($r->status === 'out', fn ($q) => $q->having(DB::raw('COALESCE(stocks_sum_quantity,0)'), '<=', 0))
            ->when($r->status === 'in', fn ($q) => $q->having('stocks_sum_quantity', '>', DB::raw('products.minimum_stock')))
            ->when($r->filled('racks'), function ($q) use ($r) {
                $q->where(function ($q2) use ($r) {
                    foreach ($r->racks as $rack) {
                        $q2->orWhere('rack_number', $rack)
                            ->orWhere('rack_number', 'like', $rack.'.%');
                    }
                });
            });

        if ($r->has('export_csv')) {
            $products = $q->get();
            $csv = "Name,Barcode,Group Name,Op. Amount,Op. Stock(Main),Purc. Price,Sale Price,Rack No\n";
            foreach ($products as $p) {
                $name = str_replace('"', '""', $p->name);
                $barcode = str_replace('"', '""', $p->code ?? '');
                $category = str_replace('"', '""', $p->category?->name ?? '');
                $stock = $p->stocks_sum_quantity ?? 0;
                $cost = $p->cost_price;
                $price = $p->price ?? $p->selling_price;
                $rack = str_replace('"', '""', $p->rack_number ?? '');
                $csv .= "\"$name\",\"$barcode\",\"$category\",\"0\",\"$stock\",\"$cost\",\"$price\",\"$rack\"\n";
            }

            return response($csv)
                ->header('Content-Type', 'text/csv')
                ->header('Content-Disposition', 'attachment; filename="stock-report-export.csv"');
        }

        $rows = (clone $q)->when($r->sort === 'rack', fn ($q) => $q->orderByRaw('LENGTH(rack_number) ASC')->orderBy('rack_number', 'ASC'), fn ($q) => $q->orderBy('name', 'ASC'))
            ->paginate($r->has('print') ? 9999 : 25)->withQueryString();

        $all = Product::withSum('stocks', 'quantity')->get();
        $stats = [
            'total' => $all->count(),
            'in_stock' => $all->filter(fn ($p) => ($p->stocks_sum_quantity ?? 0) > ($p->minimum_stock ?? 0))->count(),
            'low' => $all->filter(fn ($p) => ($p->stocks_sum_quantity ?? 0) > 0 && ($p->stocks_sum_quantity ?? 0) <= ($p->minimum_stock ?? 0))->count(),
            'out' => $all->filter(fn ($p) => ($p->stocks_sum_quantity ?? 0) <= 0)->count(),
            'value_cost' => $all->sum(fn ($p) => ($p->stocks_sum_quantity ?? 0) * $p->cost_price),
            'value_selling' => $all->sum(fn ($p) => ($p->stocks_sum_quantity ?? 0) * $p->price),
        ];

        return view('reports.stock', compact('rows', 'stats', 'from', 'to', 'rackOptions') + ['range' => $this->activeRange($r, 'all_time')]);
    }

    // ─── 6. PURCHASE REPORT ───────────────────────────────────

    public function purchases(Request $r)
    {
        [$from, $to] = $this->dateRange($r);

        $rows = Purchase::with(['supplier', 'items'])
            ->whereBetween('purchase_date', [$from, $to])
            ->when($r->status, fn ($q, $v) => $q->where('payment_status', $v))
            ->when($r->search, fn ($q, $s) => $q->where('invoice_number', 'like', "%{$s}%")
                ->orWhereHas('supplier', fn ($sq) => $sq->where('name', 'like', "%{$s}%")))
            ->latest('purchase_date')->paginate($r->has('print') ? 9999 : 25)->withQueryString();

        $stats = [
            'total' => Purchase::where('status', 'Completed')->whereBetween('purchase_date', [$from, $to])->sum('total_amount'),
            'paid' => Purchase::where('status', 'Completed')->whereBetween('purchase_date', [$from, $to])->sum('paid_amount'),
            'due' => Purchase::where('status', 'Completed')->whereBetween('purchase_date', [$from, $to])->sum('due_amount'),
            'count' => Purchase::where('status', 'Completed')->whereBetween('purchase_date', [$from, $to])->count(),
        ];

        return view('reports.purchases', compact('rows', 'stats', 'from', 'to') + ['range' => $this->activeRange($r)]);
    }

    // ─── 7. EXPENSE REPORT ────────────────────────────────────

    public function expenses(Request $r)
    {
        [$from, $to] = $this->dateRange($r);

        $rows = Expense::with(['creator'])
            ->whereBetween('expense_date', [$from, $to])
            ->when($r->category, fn ($q, $v) => $q->where('category', $v))
            ->when($r->search, fn ($q, $s) => $q->where(fn ($search) => $search->where('description', 'like', "%{$s}%")->orWhere('reference_number', 'like', "%{$s}%")))
            ->latest('expense_date')->paginate($r->has('print') ? 9999 : 25)->withQueryString();

        $stats = [
            'total' => Expense::where('status', '!=', 'Cancelled')->whereBetween('expense_date', [$from, $to])->sum('amount'),
            'count' => Expense::where('status', '!=', 'Cancelled')->whereBetween('expense_date', [$from, $to])->count(),
        ];

        $byCategory = Expense::where('status', '!=', 'Cancelled')->whereBetween('expense_date', [$from, $to])
            ->selectRaw('category, sum(amount) as total')
            ->groupBy('category')->get();

        $categories = Expense::select('category')->distinct()->whereNotNull('category')->pluck('category');

        return view('reports.expenses', compact('rows', 'stats', 'byCategory', 'categories', 'from', 'to') + ['range' => $this->activeRange($r)]);
    }

    // ─── 8. DRAWING REPORT ───────────────────────────────────

    public function drawings(Request $r)
    {
        [$from, $to] = $this->dateRange($r);

        $rows = Drawing::with(['creator'])
            ->whereBetween('drawing_date', [$from, $to])
            ->when($r->search, fn ($q, $s) => $q->where(fn ($search) => $search->where('note', 'like', "%{$s}%")->orWhere('person_name', 'like', "%{$s}%")))
            ->latest('drawing_date')->paginate($r->has('print') ? 9999 : 25)->withQueryString();

        $stats = [
            'total' => Drawing::where('status', '!=', 'Cancelled')->whereBetween('drawing_date', [$from, $to])->sum('amount'),
            'count' => Drawing::where('status', '!=', 'Cancelled')->whereBetween('drawing_date', [$from, $to])->count(),
        ];

        return view('reports.drawings', compact('rows', 'stats', 'from', 'to') + ['range' => $this->activeRange($r)]);
    }

    // ─── 9. DUE BILLS REPORT ─────────────────────────────────

    public function dueBills(Request $r)
    {
        [$from, $to] = $this->dateRange($r);

        $rows = Invoice::where('status', '!=', 'Cancelled')->with(['customer', 'bike'])
            ->where('due_amount', '>', 0)
            ->when($r->search, fn ($q, $s) => $q->where('number', 'like', "%{$s}%")
                ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$s}%")->orWhere('phone', 'like', "%{$s}%")))
            ->orderByDesc('due_amount')
            ->paginate($r->has('print') ? 9999 : 25)->withQueryString();

        $stats = [
            'total_due' => Invoice::where('due_amount', '>', 0)->sum('due_amount'),
            'due_invoices' => Invoice::where('due_amount', '>', 0)->count(),
            'customers' => Invoice::where('due_amount', '>', 0)->distinct('customer_id')->count(),
        ];

        return view('reports.due-bills', compact('rows', 'stats', 'from', 'to') + ['range' => $this->activeRange($r)]);
    }

    // ─── 10. CUSTOMER DUE REPORT ──────────────────────────────

    public function customerDue(Request $r)
    {
        [$from, $to] = $this->dateRange($r);
        $rows = Customer::with(['bikes'])
            ->withSum(['invoices' => fn ($q) => $q->where('due_amount', '>', 0)], 'due_amount')
            ->withSum('invoices', 'total')
            ->withSum('invoices', 'paid_amount')
            ->withCount('invoices')
            ->having('invoices_sum_due_amount', '>', 0)
            ->when($r->search, fn ($q, $s) => $q->where(fn ($search) => $search->where('name', 'like', "%{$s}%")->orWhere('phone', 'like', "%{$s}%")))
            ->orderByDesc('invoices_sum_due_amount')
            ->paginate($r->has('print') ? 9999 : 25)->withQueryString();

        $stats = [
            'total_due' => Invoice::where('due_amount', '>', 0)->sum('due_amount'),
            'due_customers' => Customer::whereHas('invoices', fn ($q) => $q->where('due_amount', '>', 0))->count(),
        ];

        return view('reports.customer-due', compact('rows', 'stats', 'from', 'to') + ['range' => $this->activeRange($r)]);
    }

    // ─── 11. TECHNICIAN REPORT ────────────────────────────────

    public function technicians(Request $r)
    {
        [$from, $to] = $this->dateRange($r);

        $rows = Technician::withCount(['jobs' => fn ($q) => $q->whereBetween('created_at', [$from, $to])])
            ->withCount(['jobs as completed_jobs' => fn ($q) => $q->whereBetween('created_at', [$from, $to])->where('status', 'Completed')])
            ->withSum(['commissions' => fn ($q) => $q->whereBetween('created_at', [$from, $to])], 'amount')
            ->withSum(['attendance as total_ot_amount' => fn ($q) => $q->whereBetween('date', [$from, $to])], 'ot_amount')
            ->withSum(['attendance as total_late_deduction' => fn ($q) => $q->whereBetween('date', [$from, $to])], 'late_deduction_amount')
            ->withSum(['attendance as total_early_leave_deduction' => fn ($q) => $q->whereBetween('date', [$from, $to])], 'early_leave_deduction_amount')
            ->withSum(['attendance as total_half_day_deduction' => fn ($q) => $q->whereBetween('date', [$from, $to])], 'half_day_deduction_amount')
            ->withSum(['salarySheets as total_salary_paid' => fn ($q) => $q->whereBetween('period_start', [$from, $to])], 'paid_amount')
            ->when($r->search, fn ($q, $s) => $q->where('name', 'like', "%{$s}%"))
            ->get();

        $stats = [
            'total' => Technician::count(),
            'active' => Technician::where('active', 1)->count(),
            'total_jobs' => JobCard::whereBetween('created_at', [$from, $to])->count(),
            'total_commission' => $rows->sum('commissions_sum_amount') ?? 0,
        ];

        return view('reports.technicians', compact('rows', 'stats', 'from', 'to') + ['range' => $this->activeRange($r)]);
    }

    // ─── 12. ATTENDANCE REPORT ───────────────────────────────

    public function attendance(Request $r)
    {
        [$from, $to] = $this->dateRange($r);

        $rows = TechnicianAttendance::with('technician')
            ->whereBetween('date', [$from, $to])
            ->when($r->technician, fn ($q, $v) => $q->where('technician_id', $v))
            ->when($r->status, fn ($q, $v) => $q->where('status', $v))
            ->orderByDesc('date')->paginate($r->has('print') ? 9999 : 25)->withQueryString();

        $presentStatuses = ['Present', 'Late', 'Half Day', 'Morning Half Day', 'Evening Half Day', 'Early Leave'];

        $stats = [
            'present' => TechnicianAttendance::whereBetween('date', [$from, $to])->whereIn('status', $presentStatuses)->count(),
            'absent' => TechnicianAttendance::whereBetween('date', [$from, $to])->whereIn('status', ['Absent', 'No Pay Leave'])->count(),
            'late' => TechnicianAttendance::whereBetween('date', [$from, $to])->where('status', 'Late')->count(),
            'leave' => TechnicianAttendance::whereBetween('date', [$from, $to])->whereIn('status', ['Leave', 'Paid Leave'])->count(),
        ];

        $technicians = Technician::orderBy('name')->get(['id', 'name']);

        return view('reports.attendance', compact('rows', 'stats', 'technicians', 'from', 'to') + ['range' => $this->activeRange($r)]);
    }

    // ─── 13. SALARY REPORT ───────────────────────────────────

    public function salary(Request $r)
    {
        [$from, $to] = $this->dateRange($r);

        $rows = TechnicianSalarySheet::with('technician')
            ->whereBetween('period_start', [$from, $to])
            ->when($r->technician, fn ($q, $v) => $q->where('technician_id', $v))
            ->latest('period_start')->paginate($r->has('print') ? 9999 : 25)->withQueryString();

        $stats = [
            'gross' => TechnicianSalarySheet::whereBetween('period_start', [$from, $to])->sum('gross_salary'),
            'net' => TechnicianSalarySheet::whereBetween('period_start', [$from, $to])->sum('net_payable'),
            'advances' => TechnicianSalarySheet::whereBetween('period_start', [$from, $to])->sum('advances'),
            'epf' => TechnicianSalarySheet::whereBetween('period_start', [$from, $to])->sum('epf_employee'),
        ];

        $technicians = Technician::orderBy('name')->get(['id', 'name']);

        return view('reports.salary', compact('rows', 'stats', 'technicians', 'from', 'to') + ['range' => $this->activeRange($r)]);
    }

    // ─── 14. EPF REPORT ──────────────────────────────────────

    public function epf(Request $r)
    {
        [$from, $to] = $this->dateRange($r);

        $rows = TechnicianContribution::with('technician')
            ->whereBetween('created_at', [$from, $to])
            ->when($r->technician, fn ($q, $v) => $q->where('technician_id', $v))
            ->latest()->paginate($r->has('print') ? 9999 : 25)->withQueryString();

        $sheets = TechnicianSalarySheet::whereBetween('period_start', [$from, $to])->get();
        $stats = [
            'employee_epf' => $sheets->sum('epf_employee'),
            'company_epf' => $sheets->sum('epf_company'),
            'total_epf' => $sheets->sum('epf_employee') + $sheets->sum('epf_company'),
            'eligible' => Technician::where('epf_eligible', true)->count(),
        ];

        $technicians = Technician::orderBy('name')->get(['id', 'name']);

        return view('reports.epf', compact('rows', 'stats', 'technicians', 'from', 'to') + ['range' => $this->activeRange($r)]);
    }

    // ─── 15. ETF REPORT ──────────────────────────────────────

    public function etf(Request $r)
    {
        [$from, $to] = $this->dateRange($r);

        $rows = TechnicianContribution::with('technician')
            ->whereBetween('created_at', [$from, $to])
            ->when($r->technician, fn ($q, $v) => $q->where('technician_id', $v))
            ->latest()->paginate($r->has('print') ? 9999 : 25)->withQueryString();

        $sheets = TechnicianSalarySheet::whereBetween('period_start', [$from, $to])->get();
        $stats = [
            'total_etf' => $sheets->sum('etf_company'),
            'eligible' => Technician::where('etf_eligible', true)->count(),
        ];

        $technicians = Technician::orderBy('name')->get(['id', 'name']);

        return view('reports.etf', compact('rows', 'stats', 'technicians', 'from', 'to') + ['range' => $this->activeRange($r)]);
    }

    // ─── 16. MAIN ACCOUNT REPORT ─────────────────────────────

    public function mainAccount(Request $r)
    {
        [$from, $to] = $this->dateRange($r);

        $account = Account::systemAccount('main');

        $rows = AccountTransaction::posted()->with(['creator'])
            ->when($account, fn ($q) => $q->where('account_id', $account?->id))
            ->whereBetween('transaction_date', [$from, $to])
            ->when($r->type, fn ($q, $v) => $q->where('transaction_type', $v))
            ->when($r->search, fn ($q, $s) => $q->where(fn ($search) => $search->where('description', 'like', "%{$s}%")->orWhere('transaction_no', 'like', "%{$s}%")))
            ->orderBy('transaction_date')->paginate($r->has('print') ? 9999 : 25)->withQueryString();

        $stats = [
            'total_in' => AccountTransaction::posted()->when($account, fn ($q) => $q->where('account_id', $account?->id))->whereBetween('transaction_date', [$from, $to])->sum('credit'),
            'total_out' => AccountTransaction::posted()->when($account, fn ($q) => $q->where('account_id', $account?->id))->whereBetween('transaction_date', [$from, $to])->sum('debit'),
            'balance' => $account?->balance ?? 0,
            'total_due' => Invoice::where('status', '!=', 'Cancelled')->whereBetween('created_at', [$from, $to])->where('due_amount', '>', 0)
                ->get()
                ->sum(function ($inv) {
                    return (float) $inv->total > 0 ? round((float) $inv->due_amount * (1 - min(1, (float) $inv->parts_amount / (float) $inv->total)), 2) : 0;
                }),
        ];

        return view('reports.main-account', compact('rows', 'stats', 'from', 'to') + ['range' => $this->activeRange($r)]);
    }

    // ─── 17. SPARE PARTS ACCOUNT REPORT ──────────────────────

    public function sparePartsAccount(Request $r)
    {
        [$from, $to] = $this->dateRange($r);

        $account = Account::systemAccount('spare_parts');

        $rows = AccountTransaction::posted()->with(['creator'])
            ->when($account, fn ($q) => $q->where('account_id', $account?->id))
            ->whereBetween('transaction_date', [$from, $to])
            ->when($r->type, fn ($q, $v) => $q->where('transaction_type', $v))
            ->when($r->search, fn ($q, $s) => $q->where('description', 'like', "%{$s}%"))
            ->orderBy('transaction_date')->paginate($r->has('print') ? 9999 : 25)->withQueryString();

        $stats = [
            'total_in' => AccountTransaction::posted()->when($account, fn ($q) => $q->where('account_id', $account?->id))->whereBetween('transaction_date', [$from, $to])->sum('credit'),
            'total_out' => AccountTransaction::posted()->when($account, fn ($q) => $q->where('account_id', $account?->id))->whereBetween('transaction_date', [$from, $to])->sum('debit'),
            'balance' => $account?->balance ?? 0,
            'total_due' => Invoice::where('status', '!=', 'Cancelled')->whereBetween('created_at', [$from, $to])->where('due_amount', '>', 0)
                ->get()
                ->sum(function ($inv) {
                    return (float) $inv->total > 0 ? round((float) $inv->due_amount * min(1, (float) $inv->parts_amount / (float) $inv->total), 2) : 0;
                }),
        ];

        return view('reports.spare-parts-account', compact('rows', 'stats', 'from', 'to') + ['range' => $this->activeRange($r)]);
    }

    // ─── 18. BANK ACCOUNT REPORT ─────────────────────────────

    public function bankAccount(Request $r)
    {
        [$from, $to] = $this->dateRange($r);

        $account = Account::systemAccount('bank');

        $rows = AccountTransaction::posted()->with(['creator'])
            ->when($account, fn ($q) => $q->where('account_id', $account?->id))
            ->whereBetween('transaction_date', [$from, $to])
            ->when($r->type, fn ($q, $v) => $q->where('transaction_type', $v))
            ->when($r->search, fn ($q, $s) => $q->where('description', 'like', "%{$s}%"))
            ->orderBy('transaction_date')->paginate($r->has('print') ? 9999 : 25)->withQueryString();

        $stats = [
            'total_in' => AccountTransaction::posted()->when($account, fn ($q) => $q->where('account_id', $account?->id))->whereBetween('transaction_date', [$from, $to])->sum('credit'),
            'total_out' => AccountTransaction::posted()->when($account, fn ($q) => $q->where('account_id', $account?->id))->whereBetween('transaction_date', [$from, $to])->sum('debit'),
            'balance' => $account?->balance ?? 0,
        ];

        return view('reports.bank-account', compact('rows', 'stats', 'from', 'to') + ['range' => $this->activeRange($r)]);
    }

    // ─── 19. DAILY CLOSING REPORT ────────────────────────────

    public function dailyClosing(Request $r)
    {
        [$from, $to] = $this->dateRange($r);

        $rows = DailyClosing::with(['closedBy'])
            ->whereBetween('closing_date', [$from, $to])
            ->when($r->search, fn ($q, $s) => $q->where('closing_date', 'like', "%{$s}%"))
            ->latest('closing_date')->paginate($r->has('print') ? 9999 : 25)->withQueryString();

        $stats = [
            'total_days' => DailyClosing::whereBetween('closing_date', [$from, $to])->count(),
            'main_income' => DailyClosing::whereBetween('closing_date', [$from, $to])->sum('main_income'),
            'spare_parts_income' => DailyClosing::whereBetween('closing_date', [$from, $to])->sum('spare_parts_income'),
            'total_expenses' => DailyClosing::whereBetween('closing_date', [$from, $to])->sum(DB::raw('main_expense + spare_parts_expense')),
        ];

        return view('reports.daily-closing', compact('rows', 'stats', 'from', 'to') + ['range' => $this->activeRange($r)]);
    }

    // ─── 20. SERVICE REMINDER REPORT ─────────────────────────

    public function serviceReminders(Request $r)
    {
        [$from, $to] = $this->dateRange($r);

        $rows = ServiceReminder::with('bike.customer')->whereBetween('due_date', [$from, $to])
            ->when($r->status, fn ($q, $v) => $q->where('status', $v))
            ->when($r->search, fn ($q, $s) => $q->where(fn ($x) => $x->whereHas('bike.customer', fn ($c) => $c->where('name', 'like', "%{$s}%"))
                ->orWhereHas('bike', fn ($b) => $b->where('number', 'like', "%{$s}%"))
            ))
            ->latest()->paginate($r->has('print') ? 9999 : 25)->withQueryString();

        $stats = [
            'upcoming' => ServiceReminder::where('status', 'Pending')->whereDate('due_date', '>=', today())->count(),
            'sent' => ServiceReminder::where('status', 'Sent')->count(),
            'overdue' => ServiceReminder::where('status', 'Pending')->whereDate('due_date', '<', today())->count(),
            'total' => ServiceReminder::count(),
        ];

        return view('reports.service-reminders', compact('rows', 'stats', 'from', 'to') + ['range' => $this->activeRange($r)]);
    }

    // ─── 21. SMS REPORT ──────────────────────────────────────

    public function sms(Request $r)
    {
        [$from, $to] = $this->dateRange($r);

        $rows = SmsLog::with('sender')
            ->whereBetween('created_at', [$from, $to])
            ->when($r->status, fn ($q, $v) => $q->where('status', $v))
            ->when($r->module, fn ($q, $v) => $q->where('module', $v))
            ->when($r->search, fn ($q, $s) => $q->where(fn ($x) => $x->where('phone', 'like', "%{$s}%")
                ->orWhere('phone_number', 'like', "%{$s}%")
                ->orWhere('recipient_name', 'like', "%{$s}%")
            ))
            ->latest()->paginate($r->has('print') ? 9999 : 25)->withQueryString();

        $stats = [
            'total' => SmsLog::whereBetween('created_at', [$from, $to])->count(),
            'sent' => SmsLog::whereBetween('created_at', [$from, $to])->where('status', 'sent')->count(),
            'failed' => SmsLog::whereBetween('created_at', [$from, $to])->where('status', 'failed')->count(),
            'pending' => SmsLog::whereBetween('created_at', [$from, $to])->where('status', 'pending')->count(),
        ];

        $modules = SmsLog::select('module')->distinct()->whereNotNull('module')->pluck('module');

        return view('reports.sms', compact('rows', 'stats', 'modules', 'from', 'to') + ['range' => $this->activeRange($r)]);
    }
}
