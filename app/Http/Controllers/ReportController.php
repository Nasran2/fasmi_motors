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
use App\Models\Payment;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\SaleReturn;
use App\Models\ServiceReminder;
use App\Models\SmsLog;
use App\Models\Technician;
use App\Models\TechnicianAttendance;
use App\Models\TechnicianContribution;
use App\Models\TechnicianSalarySheet;
use App\Support\ReportAccounting;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ReportController extends Controller
{
    protected const NON_SALES = ['Quotation', 'Opening Due', 'Opening Balance'];

    protected function invoiceRelations(): array
    {
        return [
            'customer' => fn ($q) => $q->withTrashed(),
            'bike' => fn ($q) => $q->withTrashed(),
            'bike.customer' => fn ($q) => $q->withTrashed(),
            'jobCard' => fn ($q) => $q->withTrashed(),
            'jobCard.intake.bike' => fn ($q) => $q->withTrashed(),
            'jobCard.intake.bike.customer' => fn ($q) => $q->withTrashed(),
            'jobCard.quickCustomer' => fn ($q) => $q->withTrashed(),
            'jobCard.quickBike' => fn ($q) => $q->withTrashed(),
        ];
    }

    protected function dateRange(Request $r, string $default = 'today'): array
    {
        $data = $r->validate([
            'date_range' => ['nullable', Rule::in(['all_time', 'today', 'yesterday', 'this_week', 'last_week', 'this_month', 'last_month', 'this_year', 'last_year', 'custom'])],
            'from' => ['nullable', 'required_with:to', 'date_format:Y-m-d'],
            'to' => ['nullable', 'required_with:from', 'date_format:Y-m-d', 'after_or_equal:from'],
            'search' => ['nullable', 'string', 'max:200'],
            'customer_id' => ['nullable', 'integer', 'min:1'],
            'bike_id' => ['nullable', 'integer', 'min:1'],
            'technician' => ['nullable', 'integer', 'min:1'],
            'supplier_id' => ['nullable', 'integer', 'min:1'],
            'store_id' => ['nullable', 'integer', 'min:1'],
            'account_id' => ['nullable', 'integer', 'min:1'],
            'category_id' => ['nullable', 'integer', 'min:1'],
            'racks' => ['nullable', 'array'],
            'racks.*' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', 'max:100'],
            'sms_status' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'string', 'max:100'],
            'drawing_type' => ['nullable', 'string', 'max:100'],
            'invoice_type' => ['nullable', 'string', 'max:100'],
            'payment_method' => ['nullable', 'string', 'max:100'],
            'payment_status' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', 'string', 'max:100'],
            'module' => ['nullable', 'string', 'max:100'],
            'active' => ['nullable', Rule::in(['0', '1'])],
            'sort' => ['nullable', Rule::in(['name', 'rack'])],
            'print' => ['nullable', 'boolean'],
            'export_csv' => ['nullable', 'boolean'],
        ]);
        if ($r->boolean('export_csv')) {
            abort_unless($r->user()->canAccess('reports.export_excel'), 403);
        }
        if ($r->boolean('print')) {
            abort_unless($r->user()->canAccess('reports.print'), 403);
        }
        $range = $this->activeRange($r, $default);

        return match ($range) {
            'all_time' => [Carbon::parse('1900-01-01')->startOfDay(), Carbon::parse('9999-12-31')->endOfDay()],
            'today' => [today()->startOfDay(), today()->endOfDay()],
            'yesterday' => [today()->subDay()->startOfDay(), today()->subDay()->endOfDay()],
            'this_week' => [today()->startOfWeek()->startOfDay(), today()->endOfDay()],
            'last_week' => [today()->subWeek()->startOfWeek()->startOfDay(), today()->subWeek()->endOfWeek()->endOfDay()],
            'this_month' => [today()->startOfMonth()->startOfDay(), today()->endOfDay()],
            'last_month' => [today()->startOfMonth()->subMonth()->startOfDay(), today()->startOfMonth()->subDay()->endOfDay()],
            'this_year' => [today()->startOfYear()->startOfDay(), today()->endOfDay()],
            'last_year' => [today()->subYear()->startOfYear()->startOfDay(), today()->subYear()->endOfYear()->endOfDay()],
            default => [Carbon::parse($data['from'] ?? today())->startOfDay(), Carbon::parse($data['to'] ?? today())->endOfDay()],
        };
    }

    protected function activeRange(Request $r, string $default = 'today'): string
    {
        return ($r->filled('from') || $r->filled('to')) ? 'custom' : $r->input('date_range', $default);
    }

    protected function rows($query, Request $r)
    {
        if (! $r->boolean('print') && ! $r->boolean('export_csv')) {
            return $query->paginate(25)->withQueryString();
        }
        $all = $query->get();

        return new LengthAwarePaginator($all, $all->count(), max(1, $all->count()), 1);
    }

    protected function render(Request $r, string $view, array $data, string $default = 'today')
    {
        $data['range'] = $this->activeRange($r, $default);
        if (! $r->boolean('export_csv')) {
            return view('reports.'.$view, $data);
        }
        // Render the same filtered, unpaginated table used for print, then encode it as CSV.
        $html = view('reports.'.$view, $data)->render();
        $doc = new \DOMDocument;
        @$doc->loadHTML('<?xml encoding="UTF-8">'.$html);
        $xpath = new \DOMXPath($doc);
        $records = [];
        foreach ($xpath->query('//*[@id="reportTable"]//tr') as $row) {
            $cells = [];
            foreach ($xpath->query('./th|./td', $row) as $cell) {
                $text = trim(preg_replace('/\s+/u', ' ', $cell->textContent));
                // Neutralize spreadsheet formula injection from customer/product text.
                $cells[] = preg_match('/^[=+@\-\t\r]/u', $text) ? "'".$text : $text;
            }
            $records[] = $cells;
        }

        return response()->streamDownload(function () use ($records) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            foreach ($records as $record) {
                fputcsv($out, $record, ',', '"', '');
            }
            fclose($out);
        }, Str::slug($view).'-'.today()->toDateString().'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    protected function jobFilters($query, Request $r)
    {
        return $query
            ->when($r->customer_id, fn ($q, $id) => $q->where(fn ($q) => $q->where('quick_customer_id', $id)->orWhereHas('intake.bike', fn ($b) => $b->where('customer_id', $id))))
            ->when($r->bike_id, fn ($q, $id) => $q->where(fn ($q) => $q->where('quick_bike_id', $id)->orWhereHas('intake', fn ($b) => $b->where('bike_id', $id))));
    }

    protected function invoiceFilters($query, Request $r, bool $paymentFilters = true)
    {
        return $query
            ->when($r->customer_id, fn ($q, $id) => $q->where(fn ($q) => $q->where('customer_id', $id)->orWhere(fn ($legacy) => $legacy->whereNull('customer_id')->whereHas('jobCard', fn ($j) => $j->where(fn ($j) => $j->where('quick_customer_id', $id)->orWhereHas('intake.bike', fn ($b) => $b->where('customer_id', $id)))))))
            ->when($r->bike_id, fn ($q, $id) => $q->where(fn ($q) => $q->where('bike_id', $id)->orWhere(fn ($legacy) => $legacy->whereNull('bike_id')->whereHas('jobCard', fn ($j) => $j->where(fn ($j) => $j->where('quick_bike_id', $id)->orWhereHas('intake', fn ($i) => $i->where('bike_id', $id)))))))
            ->when($r->invoice_type, fn ($q, $v) => $q->where('invoice_type', $v))
            ->when($paymentFilters && $r->filled('status'), fn ($q) => $q->where('status', $r->status))
            ->when($paymentFilters && $r->filled('payment_method'), fn ($q) => $q->where(fn ($q) => $q->where('payment_method', $r->payment_method)->orWhereHas('payments', fn ($p) => $p->whereIn('status', ['Completed', 'Hold'])->where('method', $r->payment_method))))
            ->when($r->search, fn ($q, $s) => $q->where(fn ($q) => $q->where('number', 'like', "%{$s}%")
                ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$s}%")->orWhere('phone', 'like', "%{$s}%"))
                ->orWhereHas('bike', fn ($b) => $b->where('number', 'like', "%{$s}%"))
                ->orWhereHas('jobCard', fn ($j) => $j->where('number', 'like', "%{$s}%")->orWhereHas('quickCustomer', fn ($c) => $c->where('name', 'like', "%{$s}%")->orWhere('phone', 'like', "%{$s}%"))->orWhereHas('quickBike', fn ($b) => $b->where('number', 'like', "%{$s}%"))->orWhereHas('intake.bike', fn ($b) => $b->where('number', 'like', "%{$s}%")->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$s}%")->orWhere('phone', 'like', "%{$s}%"))))));
    }

    protected function invoices($from, $to, bool $includeOpening = false)
    {
        return Invoice::where('status', '!=', 'Cancelled')->whereNotIn('invoice_type', $includeOpening ? ['Quotation'] : self::NON_SALES)->whereBetween('created_at', [$from, $to]);
    }

    public function index(Request $r)
    {
        [$from, $to] = $this->dateRange($r);
        $q = $this->invoiceFilters($this->invoices($from, $to), $r);
        $totals = (clone $q)->selectRaw('count(*) as total_count, COALESCE(sum(total),0) as total_sales, COALESCE(sum(paid_amount),0) as total_paid, COALESCE(sum(hold_amount),0) as total_held, COALESCE(sum(due_amount),0) as total_due, COALESCE(sum(discount),0) as total_discount')->first();
        $stats = ['invoice_count' => $totals->total_count, 'total_sales' => $totals->total_sales, 'paid_amount' => $totals->total_paid, 'held_amount' => $totals->total_held, 'due_amount' => $totals->total_due, 'discount_amount' => $totals->total_discount];
        $byType = (clone $q)->selectRaw('invoice_type, sum(total) as amount')->groupBy('invoice_type')->pluck('amount', 'invoice_type');
        $rows = $this->rows($q->with($this->invoiceRelations())->latest(), $r);

        return $this->render($r, 'sales', compact('rows', 'stats', 'totals', 'byType', 'from', 'to'));
    }

    public function jobCards(Request $r)
    {
        [$from, $to] = $this->dateRange($r);
        $q = $this->jobFilters(JobCard::whereBetween('created_at', [$from, $to]), $r)
            ->when($r->status, fn ($q, $v) => $q->where('status', $v))
            ->when($r->technician, fn ($q, $v) => $q->where('main_technician_id', $v))
            ->when($r->search, fn ($q, $s) => $q->where(fn ($q) => $q->where('number', 'like', "%{$s}%")->orWhereHas('intake.bike', fn ($b) => $b->where('number', 'like', "%{$s}%")->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$s}%")))->orWhereHas('quickBike', fn ($b) => $b->where('number', 'like', "%{$s}%"))->orWhereHas('quickCustomer', fn ($c) => $c->where('name', 'like', "%{$s}%"))));
        $stats = ['job_count' => (clone $q)->count()];
        foreach (['Completed', 'Delivered', 'Billed', 'In Progress', 'Received', 'Assigned', 'Waiting for Parts', 'Cancelled'] as $status) {
            $stats[Str::snake($status)] = (clone $q)->where('status', $status)->count();
        }
        $stats['billed_amount'] = Invoice::where('status', '!=', 'Cancelled')->whereNotIn('invoice_type', self::NON_SALES)->whereIn('job_card_id', (clone $q)->select('id'))->sum('total');
        $rows = $this->rows($q->with(['intake.bike.customer', 'technician', 'invoice'])->latest(), $r);

        return $this->render($r, 'job-cards', compact('rows', 'stats', 'from', 'to'));
    }

    public function spareParts(Request $r)
    {
        [$from, $to] = $this->dateRange($r);
        $q = InvoiceItem::whereHas('invoice', fn ($q) => $this->invoiceFilters($q->whereBetween('created_at', [$from, $to])->where('status', '!=', 'Cancelled')->where('invoice_type', 'Parts Sale'), $r->duplicate($r->except('search'))))
            ->when($r->store_id, fn ($q, $v) => $q->where('store_id', $v))
            ->when($r->search, fn ($q, $s) => $q->where(fn ($q) => $q->where('description', 'like', "%{$s}%")->orWhereHas('product', fn ($p) => $p->where('name', 'like', "%{$s}%")->orWhere('code', 'like', "%{$s}%"))))
            ->with(['invoice.items', 'product']);
        $all = (clone $q)->get();
        $stats = ['total_sales' => $all->sum(fn ($i) => ReportAccounting::itemRevenue($i)), 'items_sold' => $all->sum(fn ($i) => max(0, $i->quantity - $i->returned_quantity)), 'total_cost' => $all->sum(fn ($i) => max(0, $i->quantity - $i->returned_quantity) * $i->cost_price)];
        $stats['gross_profit'] = $stats['total_sales'] - $stats['total_cost'];
        $rows = $this->rows($q->latest(), $r);

        return $this->render($r, 'spare-parts', compact('rows', 'stats', 'from', 'to'));
    }

    public function profitLoss(Request $r)
    {
        [$from, $to] = $this->dateRange($r);
        $invoices = $this->invoices($from, $to)->get();
        $serviceIncome = $partsSales = $otherIncome = 0;
        foreach ($invoices as $invoice) {
            $revenue = ReportAccounting::revenue($invoice);
            if (in_array($invoice->invoice_type, ['Job Card Bill', 'Parts Sale'])) {
                $parts = ReportAccounting::partsRevenue($invoice);
                $partsSales += $parts;
                // Includes labour, services and additional charges after invoice discounts.
                $serviceIncome += $revenue - $parts;
            } else {
                $otherIncome += $revenue;
            }
        }
        $partsCost = InvoiceItem::whereHas('invoice', fn ($q) => $q->whereIn('id', $invoices->where('invoice_type', 'Parts Sale')->modelKeys()))->sum(DB::raw('(quantity - returned_quantity) * cost_price'));
        // External parts are recorded as expenses when purchased; do not count them twice.
        $partsCost += JobCardPart::where('is_external', false)->whereIn('job_card_id', $invoices->where('invoice_type', 'Job Card Bill')->pluck('job_card_id')->filter())->sum(DB::raw('quantity * cost_price'));
        $partsProfit = $partsSales - $partsCost;
        $totalRevenue = $serviceIncome + $partsSales + $otherIncome;
        $totalIncome = $totalRevenue - $partsCost;
        $salaryExpense = TechnicianSalarySheet::whereIn('status', ['Finalized', 'Partially Paid', 'Paid'])->whereBetween('period_end', [$from->toDateString(), $to->toDateString()])->sum(DB::raw('gross_salary + epf_company + etf_company'));
        $expenses = Expense::where('status', '!=', 'Cancelled')->whereBetween('expense_date', [$from->toDateString(), $to->toDateString()]);
        $totalExpenses = (clone $expenses)->sum('amount') + $salaryExpense;
        $totalDrawings = Drawing::where('status', '!=', 'Cancelled')->whereHas('account')->whereBetween('drawing_date', [$from->toDateString(), $to->toDateString()])->sum('amount');
        $netProfit = $totalIncome - $totalExpenses;
        $expenseByCategory = $expenses->selectRaw('category, sum(amount) as total')->groupBy('category')->toBase()->get();
        if ($salaryExpense > 0) {
            $expenseByCategory->push((object) ['category' => 'Salary and employer contributions', 'total' => $salaryExpense]);
        }

        return $this->render($r, 'profit-loss', compact('serviceIncome', 'partsSales', 'partsCost', 'partsProfit', 'otherIncome', 'totalRevenue', 'totalIncome', 'totalExpenses', 'totalDrawings', 'netProfit', 'expenseByCategory', 'from', 'to'));
    }

    public function stock(Request $r)
    {
        [$from, $to] = $this->dateRange($r, 'all_time');
        $rackOptions = Product::whereNotNull('rack_number')->where('rack_number', '!=', '')->distinct()->pluck('rack_number')->sort(SORT_NATURAL | SORT_FLAG_CASE)->values();
        $quantity = DB::table('store_stocks')->selectRaw('COALESCE(SUM(quantity),0)')->whereColumn('store_stocks.product_id', 'products.id')->when($r->store_id, fn ($q, $v) => $q->where('store_id', $v));
        $q = Product::with('category')->withSum(['stocks' => fn ($q) => $q->when($r->store_id, fn ($q, $v) => $q->where('store_id', $v))], 'quantity')
            ->when($r->category_id, fn ($q, $v) => $q->where('category_id', $v))
            ->when($r->search, fn ($q, $s) => $q->where(fn ($q) => $q->where('name', 'like', "%{$s}%")->orWhere('code', 'like', "%{$s}%")))
            ->when($r->status === 'low', fn ($q) => $q->whereRaw('('.$quantity->toSql().') > 0', $quantity->getBindings())->whereRaw('('.$quantity->toSql().') <= products.minimum_stock', $quantity->getBindings()))
            ->when($r->status === 'out', fn ($q) => $q->whereRaw('('.$quantity->toSql().') <= 0', $quantity->getBindings()))
            ->when($r->status === 'in', fn ($q) => $q->whereRaw('('.$quantity->toSql().') > products.minimum_stock', $quantity->getBindings()))
            ->when(array_filter($r->input('racks', [])), fn ($q, $racks) => $q->where(function ($q) use ($racks) {
                foreach ($racks as $rack) {
                    $q->orWhere('rack_number', $rack)->orWhere('rack_number', 'like', $rack.'.%');
                }
            }));
        $all = (clone $q)->get();
        $stats = ['product_count' => $all->count(), 'in_stock' => $all->filter(fn ($p) => ($p->stocks_sum_quantity ?? 0) > $p->minimum_stock)->count(), 'low' => $all->filter(fn ($p) => ($p->stocks_sum_quantity ?? 0) > 0 && $p->stocks_sum_quantity <= $p->minimum_stock)->count(), 'out' => $all->filter(fn ($p) => ($p->stocks_sum_quantity ?? 0) <= 0)->count(), 'value_cost' => $all->sum(fn ($p) => ($p->stocks_sum_quantity ?? 0) * $p->cost_price), 'value_selling' => $all->sum(fn ($p) => ($p->stocks_sum_quantity ?? 0) * $p->selling_price)];
        $rows = $this->rows($q->when($r->sort === 'rack', fn ($q) => $q->orderByRaw('LENGTH(rack_number) ASC')->orderBy('rack_number'), fn ($q) => $q->orderBy('name')), $r);

        return $this->render($r, 'stock', compact('rows', 'stats', 'from', 'to', 'rackOptions'), 'all_time');
    }

    protected function purchaseQuery(Request $r, $from, $to)
    {
        return Purchase::with('supplier')->where('status', 'Completed')->whereBetween('purchase_date', [$from->toDateString(), $to->toDateString()])
            ->when($r->supplier_id, fn ($q, $v) => $q->where('supplier_id', $v))->when($r->store_id, fn ($q, $v) => $q->where('store_id', $v))
            ->when($r->payment_status, fn ($q, $v) => $q->where('payment_status', $v))->when($r->payment_method, fn ($q, $v) => $q->where('payment_method', $v))
            ->when($r->search, fn ($q, $s) => $q->where(fn ($q) => $q->where('invoice_number', 'like', "%{$s}%")->orWhere('number', 'like', "%{$s}%")->orWhereHas('supplier', fn ($supply) => $supply->where('name', 'like', "%{$s}%"))));
    }

    public function purchases(Request $r)
    {
        [$from, $to] = $this->dateRange($r);
        $q = $this->purchaseQuery($r, $from, $to);
        $stats = ['total_amount' => (clone $q)->sum(DB::raw('total_amount - returned_amount')), 'paid_amount' => (clone $q)->sum('paid_amount'), 'held_amount' => (clone $q)->sum('hold_amount'), 'due_amount' => (clone $q)->sum('due_amount'), 'purchase_count' => (clone $q)->count()];
        $rows = $this->rows($q->latest('purchase_date'), $r);

        return $this->render($r, 'purchases', compact('rows', 'stats', 'from', 'to'));
    }

    public function expenses(Request $r)
    {
        [$from, $to] = $this->dateRange($r);
        $q = Expense::with('creator')->where('status', '!=', 'Cancelled')->whereBetween('expense_date', [$from->toDateString(), $to->toDateString()])
            ->when($r->category, fn ($q, $v) => $q->where('category', $v))->when($r->account_id, fn ($q, $v) => $q->where('account_id', $v))->when($r->payment_method, fn ($q, $v) => $q->where('payment_method', $v))
            ->when($r->search, fn ($q, $s) => $q->where(fn ($q) => $q->where('note', 'like', "%{$s}%")->orWhere('vendor', 'like', "%{$s}%")->orWhere('description', 'like', "%{$s}%")->orWhere('reference_number', 'like', "%{$s}%")->orWhere('number', 'like', "%{$s}%")));
        $stats = ['total_amount' => (clone $q)->sum('amount'), 'expense_count' => (clone $q)->count()];
        $byCategory = (clone $q)->selectRaw('category, sum(amount) as total')->groupBy('category')->get();
        $rows = $this->rows($q->latest('expense_date'), $r);

        return $this->render($r, 'expenses', compact('rows', 'stats', 'byCategory', 'from', 'to'));
    }

    public function drawings(Request $r)
    {
        [$from, $to] = $this->dateRange($r);
        $q = Drawing::with('creator')->where('status', '!=', 'Cancelled')->whereHas('account')->whereBetween('drawing_date', [$from->toDateString(), $to->toDateString()])
            ->when($r->drawing_type, fn ($q, $v) => $q->where('drawing_type', $v))->when($r->payment_method, fn ($q, $v) => $q->where('payment_method', $v))
            ->when($r->account_id, fn ($q, $v) => $q->where('account_id', $v))
            ->when($r->search, fn ($q, $s) => $q->where(fn ($q) => $q->where('note', 'like', "%{$s}%")->orWhere('person_name', 'like', "%{$s}%")->orWhere('reference_number', 'like', "%{$s}%")->orWhere('number', 'like', "%{$s}%")));
        $stats = ['total_amount' => (clone $q)->sum('amount'), 'drawing_count' => (clone $q)->count()];
        $rows = $this->rows($q->latest('drawing_date'), $r);

        return $this->render($r, 'drawings', compact('rows', 'stats', 'from', 'to'));
    }

    public function dueBills(Request $r)
    {
        [$from, $to] = $this->dateRange($r, 'all_time');
        $q = $this->invoiceFilters($this->invoices($from, $to, true)->where('due_amount', '>', 0), $r);
        $stats = ['total_due' => (clone $q)->sum('due_amount'), 'due_invoices' => (clone $q)->count(), 'customer_count' => (clone $q)->with($this->invoiceRelations())->get()->map(fn ($i) => $i->customer_id ?? $i->jobCard?->customer_id)->filter()->unique()->count()];
        $rows = $this->rows($q->with($this->invoiceRelations())->orderByDesc('due_amount'), $r);

        return $this->render($r, 'due-bills', compact('rows', 'stats', 'from', 'to'), 'all_time');
    }

    public function customerDue(Request $r)
    {
        [$from, $to] = $this->dateRange($r, 'all_time');
        $invoices = $this->invoiceFilters($this->invoices($from, $to, true), $r)->with($this->invoiceRelations())->get();
        $all = $invoices->groupBy(fn ($i) => $i->customer_id ?? $i->jobCard?->customer_id)->filter(fn ($group, $id) => $id && $group->sum('due_amount') > 0)->map(function ($group) {
            $invoice = $group->first();
            $source = $invoice->customer ?? $invoice->jobCard?->customer;
            $customer = $source ? clone $source : new Customer(['id' => $invoice->customer_id, 'name' => 'Archived customer']);
            $customer->invoices_count = $group->count();
            $customer->invoices_sum_total = $group->sum('total');
            $customer->invoices_sum_paid_amount = $group->sum('paid_amount');
            $customer->invoices_sum_hold_amount = $group->sum('hold_amount');
            $customer->invoices_sum_due_amount = $group->sum('due_amount');

            return $customer;
        })->sortByDesc('invoices_sum_due_amount')->values();
        $stats = ['total_due' => $all->sum('invoices_sum_due_amount'), 'due_customers' => $all->count()];
        $page = max(1, (int) $r->input('page', 1));
        $full = $r->boolean('print') || $r->boolean('export_csv');
        $rows = new LengthAwarePaginator($full ? $all : $all->forPage($page, 25), $all->count(), $full ? max(1, $all->count()) : 25, $full ? 1 : $page, ['path' => $r->url(), 'query' => $r->query()]);

        return $this->render($r, 'customer-due', compact('rows', 'stats', 'from', 'to'), 'all_time');
    }

    public function technicians(Request $r)
    {
        [$from, $to] = $this->dateRange($r);
        $rows = Technician::withCount(['jobs' => fn ($q) => $q->whereBetween('created_at', [$from, $to]), 'jobs as completed_jobs' => fn ($q) => $q->whereBetween('created_at', [$from, $to])->whereIn('status', ['Completed', 'Billed', 'Delivered'])])
            ->withSum(['commissions' => fn ($q) => $q->whereBetween('earned_date', [$from->toDateString(), $to->toDateString()])->where('status', '!=', 'Cancelled')], 'amount')
            ->withSum(['attendance as total_ot_amount' => fn ($q) => $q->whereBetween('date', [$from->toDateString(), $to->toDateString()])], 'ot_amount')
            ->withSum(['attendance as total_attendance_deductions' => fn ($q) => $q->whereBetween('date', [$from->toDateString(), $to->toDateString()])], 'deduction_amount')
            ->withSum(['salarySheets as total_salary_paid' => fn ($q) => $q->whereBetween('period_end', [$from->toDateString(), $to->toDateString()])->where('status', '!=', 'Draft')], 'paid_amount')
            ->when($r->technician, fn ($q, $v) => $q->whereKey($v))->when($r->filled('active'), fn ($q) => $q->where('active', $r->active))
            ->when($r->search, fn ($q, $s) => $q->where('name', 'like', "%{$s}%"))->orderBy('name')->get();
        $stats = ['technician_count' => $rows->count(), 'active' => $rows->where('active', true)->count(), 'job_count' => $rows->sum('jobs_count'), 'total_commission' => $rows->sum('commissions_sum_amount')];

        return $this->render($r, 'technicians', compact('rows', 'stats', 'from', 'to'));
    }

    public function attendance(Request $r)
    {
        [$from, $to] = $this->dateRange($r);
        $q = TechnicianAttendance::with('technician')->whereBetween('date', [$from->toDateString(), $to->toDateString()])->when($r->technician, fn ($q, $v) => $q->where('technician_id', $v))->when($r->status, fn ($q, $v) => $q->where('status', $v))
            ->when($r->search, fn ($q, $s) => $q->whereHas('technician', fn ($t) => $t->where('name', 'like', "%{$s}%")));
        $present = ['Present', 'Late', 'Half Day', 'Morning Half Day', 'Evening Half Day', 'Early Leave', 'Auto Closed', 'Worked on Leave Day'];
        $stats = ['present' => (clone $q)->whereIn('status', $present)->count(), 'absent' => (clone $q)->whereIn('status', ['Absent', 'No Pay Leave'])->count(), 'late' => (clone $q)->where('late_minutes', '>', 0)->count(), 'leave' => (clone $q)->whereIn('status', ['Leave', 'Paid Leave'])->count(), 'ot_amount' => (clone $q)->sum('ot_amount'), 'deduction_amount' => (clone $q)->sum('deduction_amount')];
        $rows = $this->rows($q->orderByDesc('date'), $r);

        return $this->render($r, 'attendance', compact('rows', 'stats', 'from', 'to'));
    }

    public function salary(Request $r)
    {
        [$from, $to] = $this->dateRange($r);
        $q = TechnicianSalarySheet::with('technician')->where('period_start', '<=', $to->toDateString())->where('period_end', '>=', $from->toDateString())
            ->when($r->technician, fn ($q, $v) => $q->where('technician_id', $v))->when($r->status, fn ($q, $v) => $q->where('status', $v), fn ($q) => $q->whereIn('status', ['Finalized', 'Partially Paid', 'Paid']))
            ->when($r->search, fn ($q, $s) => $q->whereHas('technician', fn ($t) => $t->where('name', 'like', "%{$s}%")));
        $stats = [];
        foreach (['gross_salary', 'net_payable', 'advances', 'epf_employee', 'paid_amount', 'balance_amount'] as $column) {
            $stats[$column === 'gross_salary' ? 'gross' : $column] = (clone $q)->sum($column);
        }
        $rows = $this->rows($q->latest('period_start'), $r);

        return $this->render($r, 'salary', compact('rows', 'stats', 'from', 'to'));
    }

    protected function contributions(Request $r, string $type)
    {
        [$from, $to] = $this->dateRange($r);
        $q = TechnicianContribution::with('technician')->whereBetween('month', [$from->copy()->startOfMonth()->toDateString(), $to->copy()->endOfMonth()->toDateString()])
            ->whereHas('salarySheet', fn ($q) => $q->whereIn('status', ['Finalized', 'Partially Paid', 'Paid']))
            ->when($r->technician, fn ($q, $v) => $q->where('technician_id', $v))
            ->when($r->search, fn ($q, $s) => $q->whereHas('technician', fn ($t) => $t->where('name', 'like', "%{$s}%")));
        $stats = $type === 'epf' ? ['employee_epf' => (clone $q)->sum('employee_epf_amount'), 'company_epf' => (clone $q)->sum('company_epf_amount'), 'total_epf' => (clone $q)->sum(DB::raw('employee_epf_amount + company_epf_amount'))] : ['total_etf' => (clone $q)->sum('company_etf_amount')];
        $stats['staff_count'] = (clone $q)->distinct()->count('technician_id');
        $rows = $this->rows($q->latest('month'), $r);

        return $this->render($r, $type, compact('rows', 'stats', 'from', 'to'));
    }

    public function epf(Request $r)
    {
        return $this->contributions($r, 'epf');
    }

    public function etf(Request $r)
    {
        return $this->contributions($r, 'etf');
    }

    protected function accountReport(Request $r, string $type, string $view)
    {
        [$from, $to] = $this->dateRange($r);
        $account = Account::systemAccount($type);
        $base = AccountTransaction::posted()->where('account_id', $account->id);
        $q = (clone $base)->whereBetween('transaction_date', [$from, $to])->when($r->type, fn ($q, $v) => $q->where('transaction_type', $v))
            ->when($r->payment_method, fn ($q, $v) => $q->where('payment_method', $v))
            ->when($r->search, fn ($q, $s) => $q->where(fn ($q) => $q->where('description', 'like', "%{$s}%")->orWhere('transaction_no', 'like', "%{$s}%")));
        $opening = (float) $account->opening_balance + (clone $base)->where('transaction_date', '<', $from)->sum(DB::raw('credit - debit'));
        $closing = $opening + (clone $base)->whereBetween('transaction_date', [$from, $to])->sum(DB::raw('credit - debit'));
        $stats = ['filtered_in_amount' => (clone $q)->sum('credit'), 'filtered_out_amount' => (clone $q)->sum('debit'), 'opening_balance' => $opening, 'closing_balance' => $closing];
        $rows = $this->rows($q->with('creator')->orderBy('transaction_date')->orderBy('id'), $r);

        return $this->render($r, $view, compact('rows', 'stats', 'from', 'to'));
    }

    public function mainAccount(Request $r)
    {
        return $this->accountReport($r, 'main', 'main-account');
    }

    public function sparePartsAccount(Request $r)
    {
        return $this->accountReport($r, 'spare_parts', 'spare-parts-account');
    }

    public function bankAccount(Request $r)
    {
        return $this->accountReport($r, 'bank', 'bank-account');
    }

    public function dailyClosing(Request $r)
    {
        [$from, $to] = $this->dateRange($r);
        $q = DailyClosing::with('closer')->whereBetween('closing_date', [$from->toDateString(), $to->toDateString()])->where('status', $r->input('status') ?: 'closed')
            ->when($r->search, fn ($q, $s) => $q->where('closing_date', 'like', "%{$s}%"));
        // Main and spare parts are logical ledgers; bank and cash mirror physical movements.
        $stats = ['day_count' => (clone $q)->count(), 'main_income' => (clone $q)->sum('main_income'), 'spare_parts_income' => (clone $q)->sum('spare_parts_income'), 'bank_income' => (clone $q)->sum('bank_income'), 'total_expenses' => (clone $q)->sum(DB::raw('main_expense + spare_parts_expense'))];
        $rows = $this->rows($q->latest('closing_date'), $r);

        return $this->render($r, 'daily-closing', compact('rows', 'stats', 'from', 'to'));
    }

    public function serviceReminders(Request $r)
    {
        [$from, $to] = $this->dateRange($r);
        $q = ServiceReminder::with('bike.customer')->when($r->sms_status, fn ($q, $v) => $q->where('sms_status', $v))->whereBetween('due_date', [$from->toDateString(), $to->toDateString()])
            ->when($r->bike_id, fn ($q, $v) => $q->where('bike_id', $v))->when($r->customer_id, fn ($q, $v) => $q->whereHas('bike', fn ($b) => $b->where('customer_id', $v)))
            ->when($r->status, fn ($q, $v) => $q->where('status', $v))->when($r->search, fn ($q, $s) => $q->where(fn ($q) => $q->whereHas('bike.customer', fn ($c) => $c->where('name', 'like', "%{$s}%")->orWhere('phone', 'like', "%{$s}%"))->orWhereHas('bike', fn ($b) => $b->where('number', 'like', "%{$s}%"))));
        $stats = ['upcoming' => (clone $q)->where('status', 'Pending')->whereDate('due_date', '>=', today())->count(), 'sent' => (clone $q)->where(fn ($q) => $q->where('sms_status', 'sent')->orWhere('status', 'Sent'))->count(), 'overdue' => (clone $q)->where('status', 'Pending')->whereDate('due_date', '<', today())->count(), 'reminder_count' => (clone $q)->count()];
        $rows = $this->rows($q->orderBy('due_date'), $r);

        return $this->render($r, 'service-reminders', compact('rows', 'stats', 'from', 'to'));
    }

    public function sms(Request $r)
    {
        [$from, $to] = $this->dateRange($r);
        $q = SmsLog::with('sender')->whereBetween('created_at', [$from, $to])->when($r->status, fn ($q, $v) => $q->where('status', $v))->when($r->module, fn ($q, $v) => $q->where('module', $v))
            ->when($r->search, fn ($q, $s) => $q->where(fn ($q) => $q->where('phone', 'like', "%{$s}%")->orWhere('phone_number', 'like', "%{$s}%")->orWhere('recipient_name', 'like', "%{$s}%")));
        $stats = ['message_count' => (clone $q)->count(), 'sent' => (clone $q)->where('status', 'sent')->count(), 'failed' => (clone $q)->where('status', 'failed')->count(), 'pending' => (clone $q)->where('status', 'pending')->count()];
        $rows = $this->rows($q->latest(), $r);

        return $this->render($r, 'sms', compact('rows', 'stats', 'from', 'to'));
    }

    public function collections(Request $r)
    {
        [$from, $to] = $this->dateRange($r);
        $q = Payment::with(['invoice' => fn ($q) => $q->with($this->invoiceRelations())])
            ->whereBetween('paid_at', [$from, $to])->whereIn('status', ['Completed', 'Hold'])
            ->whereHas('invoice', fn ($q) => $this->invoiceFilters($q->where('status', '!=', 'Cancelled')->where('invoice_type', '!=', 'Quotation'), $r->duplicate($r->except('search')), false))
            ->when($r->payment_method, fn ($q, $v) => $q->where('method', $v))->when($r->status, fn ($q, $v) => $q->where('status', $v))
            ->when($r->search, fn ($q, $s) => $q->where(fn ($q) => $q->where('number', 'like', "%{$s}%")->orWhereHas('invoice', fn ($i) => $this->invoiceFilters($i, $r, false))));
        $money = fn ($q) => $q->whereNotIn('method', ['Salary Deduction', 'Manual Clearance']);
        $stats = ['receipt_count' => (clone $q)->count(), 'collected_amount' => $money((clone $q)->where('status', 'Completed'))->sum(DB::raw('amount - refunded_amount')), 'held_amount' => (clone $q)->where('status', 'Hold')->sum(DB::raw('amount - refunded_amount')), 'offset_amount' => (clone $q)->where('status', 'Completed')->whereIn('method', ['Salary Deduction', 'Manual Clearance'])->sum('amount')];
        $rows = $this->rows($q->latest('paid_at'), $r);

        return $this->render($r, 'collections', compact('rows', 'stats', 'from', 'to'));
    }

    public function supplierDues(Request $r)
    {
        [$from, $to] = $this->dateRange($r, 'all_time');
        $q = $this->purchaseQuery($r, $from, $to)->where('due_amount', '>', 0);
        $stats = ['due_amount' => (clone $q)->sum('due_amount'), 'held_amount' => (clone $q)->sum('hold_amount'), 'bill_count' => (clone $q)->count(), 'supplier_count' => (clone $q)->distinct()->count('supplier_id')];
        $rows = $this->rows($q->orderByDesc('due_amount'), $r);

        return $this->render($r, 'supplier-dues', compact('rows', 'stats', 'from', 'to'), 'all_time');
    }

    public function salesReturns(Request $r)
    {
        [$from, $to] = $this->dateRange($r);
        $q = SaleReturn::with(['invoice' => fn ($q) => $q->with($this->invoiceRelations())])
            ->whereBetween('created_at', [$from, $to])->whereHas('invoice', fn ($q) => $this->invoiceFilters($q, $r));
        $stats = ['return_count' => (clone $q)->count(), 'credit_amount' => (clone $q)->sum('amount'), 'refund_amount' => (clone $q)->sum('refund_amount')];
        $rows = $this->rows($q->latest(), $r);

        return $this->render($r, 'sales-returns', compact('rows', 'stats', 'from', 'to'));
    }
}
