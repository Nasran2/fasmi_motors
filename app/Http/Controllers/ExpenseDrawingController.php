<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\Drawing;
use App\Models\Expense;
use App\Models\FinancialCategory;
use App\Services\AccountService;
use App\Services\OutgoingFundingService;
use App\Support\PrintDocument;
use App\Support\SpreadsheetSafeText;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ExpenseDrawingController extends Controller
{
    public function expenses(Request $request)
    {
        return $this->listing($request, 'expense');
    }

    public function drawings(Request $request)
    {
        return $this->listing($request, 'drawing');
    }

    public function showExpense(Expense $expense)
    {
        return $this->showRecord($expense, 'expense');
    }

    public function showDrawing(Drawing $drawing)
    {
        return $this->showRecord($drawing, 'drawing');
    }

    private function showRecord(Expense|Drawing $record, string $module)
    {
        $record->load(['account', 'creator']);
        abort_if($record->account_id && ! $record->account, 404);

        return view('finance-management.show', compact('record', 'module'));
    }

    public function createExpense()
    {
        return $this->form('expense');
    }

    public function createDrawing()
    {
        return $this->form('drawing');
    }

    public function expenseCategories()
    {
        return $this->categories('expense');
    }

    public function drawingCategories()
    {
        return $this->categories('drawing');
    }

    private function listing(Request $request, string $module)
    {
        [$from, $to, $range] = $this->range($request);
        $model = $module === 'expense' ? Expense::query() : Drawing::query();
        $date = $module.'_date';
        $model->with(['account', 'creator'])->whereBetween($date, [$from, $to])->when($request->q, fn ($q, $v) => $q->where(fn ($n) => $n->where('number', 'like', "%$v%")->orWhere('note', 'like', "%$v%")->orWhere('reference_number', 'like', "%$v%")->when($module === 'expense', fn ($e) => $e->orWhere('description', 'like', "%$v%"))))->when($request->category, fn ($q, $v) => $q->where($module === 'expense' ? 'category' : 'drawing_type', $v))->when($request->account_id, fn ($q, $v) => $q->where('account_id', $v))->when($request->payment_method, fn ($q, $v) => $q->where('payment_method', $v));
        $records = (clone $model)->latest($date)->paginate(20)->withQueryString();
        $all = (clone $model)->get();
        $accounts = Account::where('active', true)->get();
        $categories = FinancialCategory::where('module', $module)->where('active', true)->orderBy('name')->get();
        $today = (float) ($module === 'expense' ? Expense::whereDate('expense_date', today())->where('status', '!=', 'Cancelled')->sum('amount') : Drawing::whereDate('drawing_date', today())->where('status', '!=', 'Cancelled')->sum('amount'));

        return view('finance-management.list', compact('module', 'records', 'accounts', 'categories', 'from', 'to', 'range', 'today', 'all'));
    }

    private function form(string $module)
    {
        Account::ensureSystemAccounts();
        $accounts = Account::where('active', true)->get();
        $categories = FinancialCategory::where('module', $module)->where('active', true)->orderBy('name')->get();
        $default = $accounts->firstWhere('code', Account::systemCode($module === 'expense' ? 'main' : 'spare_parts'))?->id;

        return view('finance-management.form', compact('module', 'accounts', 'categories', 'default'));
    }

    private function categories(string $module)
    {
        $categories = FinancialCategory::with('account')->where('module', $module)->get();
        $accounts = Account::where('active', true)->get();

        return view('finance-management.categories', compact('module', 'categories', 'accounts'));
    }

    public function storeCategory(Request $r, string $module)
    {
        abort_unless(in_array($module, ['expense', 'drawing']), 404);
        $v = $r->validate(['name' => 'required|max:100', 'default_account_id' => 'nullable|exists:accounts,id', 'description' => 'nullable|max:500', 'active' => 'nullable|boolean']);
        FinancialCategory::updateOrCreate(['module' => $module, 'name' => $v['name']], $v + ['active' => $r->boolean('active', true)]);

        return back()->with('success', 'Category saved.');
    }

    public function storeExpense(Request $r, AccountService $service)
    {
        return $this->store($r, 'expense', $service);
    }

    public function storeDrawing(Request $r, AccountService $service)
    {
        return $this->store($r, 'drawing', $service);
    }

    private function store(Request $r, string $module, AccountService $service)
    {
        $base = ['account_id' => 'required|exists:accounts,id', 'amount' => 'required|numeric|gt:0', 'payment_method' => 'required|in:Cash,Bank Transfer,Card,Cheque,QR', 'reference_number' => 'nullable|max:100', 'cheque_number' => 'nullable|required_if:payment_method,Cheque|max:100', 'note' => 'nullable|max:1000'];
        $specific = $module === 'expense' ? ['expense_date' => 'required|date', 'category' => 'required|max:100', 'description' => 'required|max:1000', 'vendor' => 'nullable|max:150', 'attachment' => 'nullable|file|max:4096'] : ['drawing_date' => 'required|date', 'person_name' => 'required|max:150', 'drawing_type' => 'required|max:100'];
        $v = $r->validate($base + $specific);
        $fundingService = app(OutgoingFundingService::class);
        $plan = $fundingService->plan($r->all(), (float) $v['amount'], $fundingService->defaultSource((int) $v['account_id'], $v['payment_method'], $module === 'expense' ? 'main' : 'spare_parts'));
        DB::transaction(function () use ($v, $module, $fundingService, $plan, $r) {
            $v['account_id'] = Account::systemAccount($fundingService->type($plan[0]['source']))->id;
            if ($module === 'expense') {
                if ($r->hasFile('attachment')) {
                    $v['attachment'] = $r->file('attachment')->store('expense-receipts', 'public');
                }
                $record = Expense::create($v + ['funding_allocations' => $plan, 'number' => 'EXP-'.now()->format('ymd').'-'.Str::upper(Str::random(5)), 'status' => 'Active', 'created_by' => auth()->id()]);
            } else {
                $record = Drawing::create($v + ['number' => 'DRW-'.now()->format('ymd').'-'.Str::upper(Str::random(5)), 'status' => 'Active', 'created_by' => auth()->id()]);
            }
            $fundingService->post($plan, (float) $v['amount'], $module === 'expense' ? $v['description'] : "Drawing by {$v['person_name']}", $record,
                $v['payment_method'], $v[$module.'_date'], $module === 'expense' ? $v['category'] : $v['drawing_type'], $v['note'] ?? null, $module);
        });
        $route = $r->input('action') === 'add_another' ? ($module === 'expense' ? 'expenses.create' : 'drawings.create') : ($module === 'expense' ? 'expenses.index' : 'drawings.index');

        return redirect()->route($route)->with('success', ucfirst($module).' saved and ledger updated.');
    }

    public function cancel(Request $r, string $module, int $id, AccountService $service)
    {
        abort_unless(in_array($module, ['expense', 'drawing'], true), 404);
        $r->validate(['reason' => 'required|min:5|max:500']);
        $record = ($module === 'expense' ? Expense::findOrFail($id) : Drawing::findOrFail($id));
        abort_if($module === 'expense' && $record->jobPart()->exists(), 422, 'Edit or remove this outer product from its job card to update the expense and profit together.');
        abort_if($record->status === 'Cancelled', 422);
        DB::transaction(function () use ($record, $r, $service) {
            $record = $record->newQuery()->lockForUpdate()->findOrFail($record->id);
            abort_if($record->status === 'Cancelled', 422);
            AccountTransaction::where('reference_type', $record->getMorphClass())->where('reference_id', $record->id)->where('status', 'active')->get()->each(fn ($t) => $service->reverseTransaction($t, $r->reason));
            $record->update(['status' => 'Cancelled', 'cancel_reason' => $r->reason, 'cancelled_by' => auth()->id(), 'cancelled_at' => now()]);
        });

        return back()->with('success', 'Record cancelled with a ledger reversal.');
    }

    public function export(Request $r, string $module)
    {
        abort_unless(in_array($module, ['expense', 'drawing'], true), 404);
        [$from, $to] = $this->range($r);
        $date = $module.'_date';
        $rows = ($module === 'expense' ? Expense::with('account', 'creator') : Drawing::with('account', 'creator'))->whereBetween($date, [$from, $to])
            ->when($r->q, fn ($q, $value) => $q->where(fn ($q) => $q->where('number', 'like', "%{$value}%")->orWhere('note', 'like', "%{$value}%")->orWhere('reference_number', 'like', "%{$value}%")->when($module === 'expense', fn ($q) => $q->orWhere('description', 'like', "%{$value}%"))))
            ->when($r->category, fn ($q, $value) => $q->where($module === 'expense' ? 'category' : 'drawing_type', $value))
            ->when($r->account_id, fn ($q, $value) => $q->where('account_id', $value))
            ->when($r->payment_method, fn ($q, $value) => $q->where('payment_method', $value))
            ->orderBy($date)->orderBy('id')->get();
        if ($r->format === 'excel') {
            return response()->streamDownload(function () use ($rows, $module, $date) {
                $f = fopen('php://output', 'w');
                fputcsv($f, ['Date', 'Number', 'Category / Person', 'Account', 'Method', 'Amount', 'Status']);
                foreach ($rows as $x) {
                    fputcsv($f, SpreadsheetSafeText::row([$x->getAttribute($date)?->format('Y-m-d'), $x->number, $module === 'expense' ? $x->category : $x->getAttribute('person_name'), $x->account?->getAttribute('name'), $x->payment_method, $x->amount, $x->status]));
                }
                fclose($f);
            }, "{$module}-report.csv", ['Content-Type' => 'text/csv']);
        }

        return PrintDocument::pdf('finance-management.report', compact('rows', 'module', 'from', 'to'))->download("{$module}-report.pdf");
    }

    private function range(Request $r)
    {
        $r->validate([
            'date_range' => ['nullable', 'in:today,yesterday,this_week,last_week,this_month,last_month,this_year,last_year,custom'],
            'from' => ['nullable', 'required_if:date_range,custom', 'date_format:Y-m-d'],
            'to' => ['nullable', 'required_if:date_range,custom', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $range = $r->date_range ?: 'today';
        [$f, $t] = match ($range) {
            'yesterday' => [today()->subDay(), today()->subDay()],
            'this_week' => [now()->startOfWeek(), now()->endOfWeek()],
            'last_week' => [now()->subWeek()->startOfWeek(), now()->subWeek()->endOfWeek()],
            'this_month' => [now()->startOfMonth(), now()->endOfMonth()],
            'last_month' => [now()->subMonth()->startOfMonth(), now()->subMonth()->endOfMonth()],
            'this_year' => [now()->startOfYear(), now()->endOfYear()],
            'last_year' => [now()->subYear()->startOfYear(), now()->subYear()->endOfYear()],
            'custom' => [Carbon::parse($r->from ?: today()), Carbon::parse($r->to ?: today())],
            default => [today(), today()]
        };

        return [$f->toDateString(), $t->toDateString(), $range];
    }
}
