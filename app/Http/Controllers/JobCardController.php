<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Bike;
use App\Models\BikeIntake;
use App\Models\Invoice;
use App\Models\JobAdvance;
use App\Models\JobCard;
use App\Models\PaymentCorrection;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Store;
use App\Models\Technician;
use App\Services\AccountService;
use App\Services\BikeRegistrationService;
use App\Services\BillDetailsService;
use App\Services\JobCommissionService;
use App\Services\JobPartService;
use App\Services\SettlementService;
use App\Services\StaffBikeRepairService;
use App\Services\WashingCommissionService;
use App\Services\WorkshopService;
use App\Support\JobPaymentSummary;
use App\Support\PreviousCustomerBalance;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class JobCardController extends Controller
{
    public function index(Request $r)
    {
        $data = $r->validate(['bike_id' => 'nullable|integer|min:1', 'bike_search' => 'nullable|string|max:60']);
        $q = JobCard::with('intake.bike.customer', 'technician', 'invoice')->latest()->latest('id');
        $search = preg_replace('/[^a-z0-9]/i', '', $data['bike_search'] ?? '');
        if ($search !== '') {
            $normalize = fn ($column) => "LOWER(REPLACE(REPLACE(REPLACE(REPLACE({$column}, ' ', ''), '-', ''), '.', ''), '/', ''))";
            $match = fn ($bike) => $bike->whereRaw($normalize('number').' LIKE ?', ['%'.strtolower($search).'%']);
            $q->where(fn ($jobs) => $jobs->whereHas('intake.bike', $match)->orWhereHas('quickBike', $match)
                ->orWhere(fn ($quick) => $quick->where('is_quick_job', true)->whereNull('quick_bike_id')
                    ->whereRaw($normalize("JSON_UNQUOTE(JSON_EXTRACT(quick_bike_details, '$.number'))").' LIKE ?', ['%'.strtolower($search).'%'])));
        } elseif ($r->filled('bike_id')) {
            $q->where(fn ($query) => $query->where('quick_bike_id', $r->integer('bike_id'))
                ->orWhereHas('intake', fn ($intake) => $intake->where('bike_id', $r->integer('bike_id'))));
        }
        if ($r->status) {
            if ($r->status === 'Completed') {
                $q->whereIn('status', ['Completed', 'Billed', 'Delivered']);
            } else {
                $q->where('status', $r->status);
            }
        }

        $jobs = $q->paginate(15)->withQueryString();
        $previousBalancesByJob = $jobs->getCollection()->mapWithKeys(fn (JobCard $job) => [$job->id => PreviousCustomerBalance::forJob($job)]);

        if ($r->expectsJson()) {
            return response()->json([
                'html' => view('jobs.partials.list', compact('jobs', 'previousBalancesByJob'))->render(),
                'total' => $jobs->total(),
            ])->header('Cache-Control', 'no-store')->header('Vary', 'Accept');
        }

        return view('jobs.index', [
            'jobs' => $jobs,
            'previousBalancesByJob' => $previousBalancesByJob,
            'bikes' => Bike::where(fn ($query) => $query->whereHas('intakes.jobCard')
                ->orWhereIn('id', JobCard::whereNotNull('quick_bike_id')->select('quick_bike_id')))->orderBy('number')->get(['id', 'number']),
            'statuses' => self::statuses(),
            'accounts' => Account::where('active', 1)->get(),
        ]);
    }

    public function create(Request $r)
    {
        return view('jobs.create', ['intakes' => BikeIntake::doesntHave('jobCard')->with('bike.customer')->latest()->get(), 'selected' => $r->intake, 'technicians' => Technician::where('active', 1)->get()]);
    }

    public function store(Request $r)
    {
        $v = $r->validate(['bike_intake_id' => 'required|exists:bike_intakes,id|unique:job_cards', 'job_date' => 'sometimes|required|date_format:Y-m-d', 'main_technician_id' => 'nullable|exists:technicians,id', 'priority' => 'required|in:Low,Normal,High,Urgent', 'estimated_amount' => 'nullable|numeric|min:0'] + WashingCommissionService::rules());
        $v['estimated_amount'] = $v['estimated_amount'] ?? 0;
        $v += app(WashingCommissionService::class)->defaults();
        $job = DB::transaction(function () use ($v, $r) {
            $intake = BikeIntake::lockForUpdate()->findOrFail($v['bike_intake_id']);
            $createdAt = now()->setDateFrom($v['job_date'] ?? now());
            unset($v['job_date']);
            $bike = $intake->bike;
            $v['is_staff_bike'] = (bool) $bike?->is_technician_bike;
            $v['staff_bike_owner_id'] = $bike?->is_technician_bike ? $bike->technician_id : null;
            $job = JobCard::create($v + ['number' => 'JOB-'.$createdAt->format('ymd').'-'.Str::upper(Str::random(6)), 'created_at' => $createdAt, 'status' => $r->main_technician_id ? 'Assigned' : 'Received', 'created_by' => auth()->id(), 'internal_notes' => $r->internal_notes]);
            DB::table('job_card_status_logs')->insert(['job_card_id' => $job->id, 'new_status' => $job->status, 'changed_by' => auth()->id(), 'created_at' => now(), 'updated_at' => now()]);

            return $job;
        });

        return redirect()->route('jobs.show', $job)->with('success', 'Job card created.');
    }

    public function show(JobCard $job)
    {
        $products = Product::with([
            'stocks',
            'stockBatches' => fn ($query) => $query->where('status', 'active')->where('available_qty', '>', 0),
        ])->where('active', 1)->orderBy('name')->orderBy('code')->orderBy('id')->get();

        $productBatchOptions = $products->map(fn (Product $product) => [
            'id' => $product->id,
            'price' => (float) $product->selling_price,
            'cost' => (float) $product->cost_price,
            'stocks' => $product->stocks->mapWithKeys(fn ($stock) => [$stock->store_id => (float) $stock->quantity]),
            'minimum_stock' => (float) $product->minimum_stock,
            'batches' => $product->stockBatches->map(fn ($batch) => [
                'id' => $batch->id,
                'store_id' => $batch->store_id,
                'batch_no' => $batch->batch_no,
                'cost' => (float) $batch->cost_price,
                'selling' => (float) $batch->selling_price,
                'qty' => (float) $batch->available_qty,
            ])->values(),
        ])->values();

        $statusLogs = DB::table('job_card_status_logs')
            ->leftJoin('users', 'job_card_status_logs.changed_by', '=', 'users.id')
            ->where('job_card_id', $job->id)
            ->select('job_card_status_logs.*', 'users.name as changer_name')
            ->orderBy('job_card_status_logs.created_at', 'desc')
            ->get();

        $job->load('intake.bike.customer', 'technician', 'parts.product.stocks', 'parts.expense.account', 'services', 'invoice.payments');
        $advances = JobAdvance::with('account', 'cheque')->where('job_card_id', $job->id)->whereNull('payment_id')->orderBy('id')->get();
        $previousBalances = PreviousCustomerBalance::forJob($job);
        $oldDue = PreviousCustomerBalance::total($previousBalances);
        $relatedInvoiceIds = PreviousCustomerBalance::forJob($job, includeSettled: true)->pluck('invoice_id')->push($job->invoice?->id)->filter();
        $paymentInvoices = Invoice::with('payments.cheque', 'payments.account', 'payments.invoice', 'payments.salarySheet')->whereIn('id', $relatedInvoiceIds)->where('status', '!=', 'Cancelled')->get();
        $paymentSummary = JobPaymentSummary::data($job);
        $receiptGroups = $paymentInvoices->flatMap(fn ($invoice) => $invoice->payments)->sortBy('id')
            ->groupBy(fn ($receipt) => $receipt->receipt_group_id ?: 'legacy-'.$receipt->id);
        $paymentChanges = PaymentCorrection::with('changer')->where(function ($query) use ($job) {
            $query->where('job_card_id', $job->id)->orWhereIn('invoice_id', Invoice::where('job_card_id', $job->id)->select('id'));
        })->get();
        $history = $statusLogs->map(fn ($log) => ['kind' => 'status', 'record' => $log])
            ->merge($paymentChanges->map(fn ($change) => ['kind' => 'payment', 'record' => $change]))
            ->sortByDesc(fn ($entry) => Carbon::parse($entry['record']->created_at)->getTimestamp())->values();

        return view('jobs.show', [
            'job' => $job,
            'advances' => $advances,
            'staffRepairSummary' => $job->repair_staff ? app(StaffBikeRepairService::class)->summary($job) : null,
            'oldDue' => $oldDue,
            'previousBalances' => $previousBalances,
            'paymentInvoices' => $paymentInvoices,
            'paymentSummary' => $paymentSummary,
            'receiptGroups' => $receiptGroups,
            'history' => $history,
            'technicians' => Technician::where('active', 1)->get(),
            'products' => $products,
            'productBatchOptions' => $productBatchOptions,
            'stores' => Store::where('active', 1)->get(),
            'statuses' => self::statuses(),
            'accounts' => Account::where('active', 1)->get(),
            'statusLogs' => $statusLogs,
            'productCategories' => ProductCategory::orderBy('name')->get(),
            'selectedProductId' => request()->integer('product') ?: null,
            'selectedStoreId' => request()->integer('store') ?: null,
        ]);
    }

    public function bikeNumber(Request $request, JobCard $job, BikeRegistrationService $service)
    {
        try {
            $service->correct($job, $request->validateWithBag('bikeNumber', BikeRegistrationService::rules()));
        } catch (ValidationException $exception) {
            throw $exception->errorBag('bikeNumber');
        }

        return back()->with('success', 'Bike number corrected across all linked intakes, job cards, bills and reports.');
    }

    public function billDetails(Request $request, JobCard $job, BillDetailsService $service)
    {
        $service->updateJob($job, $request->validate(BillDetailsService::rules()));

        return back()->with('success', 'Bill date updated. Existing payment dates are unchanged.');
    }

    public function update(Request $r, JobCard $job)
    {
        return DB::transaction(function () use ($r, $job) {
            $job = JobCard::lockForUpdate()->findOrFail($job->id);
            $isCurrentLocked = in_array($job->status, ['Billed', 'Completed', 'Delivered']);

            if ($isCurrentLocked) {
                $v = $r->validate([
                    'status' => 'required|in:'.implode(',', self::statuses()),
                    'change_reason' => 'nullable|string|max:500',
                ]);
            } else {
                $v = $r->validate([
                    'status' => 'required|in:'.implode(',', self::statuses()),
                    'main_technician_id' => 'nullable|required_if:status,Assigned|exists:technicians,id',
                    'change_reason' => 'nullable|max:500',
                    'parts_total' => 'nullable|numeric|min:0',
                    'service_total' => 'nullable|numeric|min:0',
                    'labor_total' => 'nullable|numeric|min:0',
                    'washing_total' => 'nullable|numeric|min:0',
                    'other_total' => 'nullable|numeric|min:0',
                    'discount' => 'nullable|numeric|min:0',
                    'discount_type' => 'nullable|in:fixed,percentage',
                    'discount_value' => 'required_with:discount_type|nullable|numeric|min:0|max:999999999999.99',
                    'discount_percentage' => 'nullable|numeric|decimal:0,2|min:0|max:100',
                    'fixed_discount' => 'nullable|numeric|decimal:0,2|min:0|max:999999999999.99',
                    'tax' => 'nullable|numeric|min:0',
                    'show_washing_on_bill' => 'nullable|boolean',
                    'show_labor_on_bill' => 'nullable|boolean',
                    'service_name' => 'nullable|string|max:50',
                    'other_income_name' => 'nullable|string|max:50',
                ] + WashingCommissionService::rules());

                // Cleared amounts mean zero; omitted amounts keep their saved values.
                foreach (['parts_total', 'service_total', 'labor_total', 'washing_total', 'other_total', 'tax'] as $field) {
                    if (array_key_exists($field, $v)) {
                        $v[$field] ??= 0;
                    }
                }

                if ($r->hasAny(['discount_percentage', 'fixed_discount'])) {
                    unset($v['discount_type'], $v['discount_value'], $v['discount']);
                    foreach (['discount_percentage', 'fixed_discount'] as $field) {
                        if (array_key_exists($field, $v)) {
                            $v[$field] ??= 0;
                        }
                    }
                } elseif (isset($v['discount_type'])) {
                    if ($v['discount_type'] === 'percentage' && (float) $v['discount_value'] > 100) {
                        throw ValidationException::withMessages(['discount_value' => 'Percentage discount must be between 0 and 100.']);
                    }
                    $v['discount_percentage'] = $v['discount_type'] === 'percentage' ? $v['discount_value'] : 0;
                    $v['fixed_discount'] = $v['discount_type'] === 'percentage' ? 0 : $v['discount_value'];
                    unset($v['discount']);
                } elseif (isset($v['discount'])) {
                    $v['discount_type'] = 'fixed';
                    $v['discount_value'] = $v['discount'];
                    $v['discount_percentage'] = 0;
                    $v['fixed_discount'] = $v['discount'];
                } else {
                    unset($v['discount_type'], $v['discount_value'], $v['discount']);
                }

                // Checkboxes are only sent if checked, handle defaults
                $v['show_washing_on_bill'] = $r->has('show_washing_on_bill');
                $v['show_labor_on_bill'] = $r->has('show_labor_on_bill');
            }

            $old = $job->status;
            $r->validate(['show_previous_balance_on_bill' => 'sometimes|required|boolean']);
            if ($r->has('show_previous_balance_on_bill')) {
                $v['show_previous_balance_on_bill'] = $r->boolean('show_previous_balance_on_bill');
            }
            $changeReason = $v['change_reason'] ?? null;
            unset($v['change_reason']);
            $job->update($v);

            if ($old !== $job->status) {
                DB::table('job_card_status_logs')->insert([
                    'job_card_id' => $job->id,
                    'old_status' => $old,
                    'new_status' => $job->status,
                    'note' => $changeReason,
                    'changed_by' => auth()->id(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $this->syncInvoice($job);

            return back()->with('success', 'Job card updated.');
        });
    }

    public function washingAssignment(Request $request, JobCard $job, WashingCommissionService $service)
    {
        $values = $request->validate(WashingCommissionService::rules());
        if (! array_key_exists('washing_person_id', $values) || ! array_key_exists('washing_commission_amount', $values)) {
            throw ValidationException::withMessages(['washing_person_id' => 'Select a washing assignment and fixed commission amount.']);
        }
        DB::transaction(function () use ($job, $values, $service) {
            $job = JobCard::lockForUpdate()->findOrFail($job->id);
            if ($job->status === 'Cancelled') {
                throw ValidationException::withMessages(['washing_person_id' => 'A cancelled job cannot be reassigned.']);
            }
            $job->update($values);
            $service->sync($job);
        });

        return back()->with('success', 'Washing person and commission updated.');
    }

    public function addPart(Request $r, JobCard $job, JobPartService $parts)
    {
        $data = $r->validate($this->stockPartRules());
        DB::transaction(function () use ($job, $data, $parts) {
            $job = JobCard::lockForUpdate()->findOrFail($job->id);
            $parts->addStock($job, $data);
            $this->refreshParts($job);
        });

        return redirect(route('jobs.show', $job).'#spare-parts')->with('success', 'Part added successfully.');
    }

    public function addMultipleParts(Request $r, JobCard $job, JobPartService $parts)
    {
        $rules = ['parts' => 'required|array|min:1|max:100'];
        foreach ($this->stockPartRules() as $field => $rule) {
            $rules['parts.*.'.$field] = $rule;
        }
        $data = $r->validate($rules);
        DB::transaction(function () use ($job, $data, $parts) {
            $job = JobCard::lockForUpdate()->findOrFail($job->id);
            foreach ($data['parts'] as $line) {
                $parts->addStock($job, $line);
            }
            $this->refreshParts($job);
        });

        return back()->with('success', 'Parts added successfully.');
    }

    public function addOuterPart(Request $r, JobCard $job, JobPartService $parts)
    {
        abort_unless($r->user()->canAccess('expense.add'), 403);
        $r->merge(['quantity' => $r->input('quantity', 1)]);
        $data = $r->validate([
            'display_name' => 'required|string|max:255', 'note' => 'nullable|string|max:255',
            'cost_price' => 'required|numeric|decimal:0,2|min:0|max:99999999.99',
            'unit_price' => 'required|numeric|decimal:0,2|min:0|max:99999999.99',
            'quantity' => 'required|numeric|decimal:0,2|min:0.01|max:999999.99', 'account_id' => 'required|exists:accounts,id',
        ]);
        DB::transaction(function () use ($job, $data, $parts) {
            $job = JobCard::lockForUpdate()->findOrFail($job->id);
            $parts->addOuter($job, $data);
            $this->refreshParts($job);
        });

        return redirect(route('jobs.show', $job).'#spare-parts')->with('success', 'Outer product added and its purchase cost recorded in Expenses.');
    }

    public function newProduct(Request $r, JobCard $job, WorkshopService $service, JobPartService $parts)
    {
        abort_unless($r->user()->canAccess('job_card.add_parts'), 403);

        return DB::transaction(function () use ($r, $job, $service, $parts) {
            $job = JobCard::lockForUpdate()->findOrFail($job->id);
            $parts->ensureEditable($job);

            return app(ProductController::class)->store($r, $service);
        });
    }

    public function updatePart(Request $r, JobCard $job, $partId, JobPartService $parts)
    {
        $data = $r->validate([
            'display_name' => 'sometimes|required|string|max:255', 'note' => 'nullable|string|max:255',
            'cost_price' => 'sometimes|required|numeric|decimal:0,2|min:0|max:99999999.99',
            'unit_price' => 'required|numeric|decimal:0,2|min:0|max:99999999.99',
            'discount' => 'sometimes|required|numeric|decimal:0,2|min:0|max:99999999.99',
            'quantity' => 'required|numeric|decimal:0,2|min:0.01|max:999999.99', 'account_id' => 'nullable|exists:accounts,id',
        ]);
        DB::transaction(function () use ($job, $partId, $data, $parts) {
            $job = JobCard::lockForUpdate()->findOrFail($job->id);
            $part = $job->parts()->lockForUpdate()->findOrFail($partId);
            $parts->update($job, $part, $data);
            $this->refreshParts($job);
        });

        return redirect(route('jobs.show', $job).'#spare-parts')->with('success', 'Part updated successfully.');
    }

    public function deletePart(JobCard $job, $partId, JobPartService $parts)
    {
        DB::transaction(function () use ($job, $partId, $parts) {
            $job = JobCard::lockForUpdate()->findOrFail($job->id);
            $part = $job->parts()->lockForUpdate()->findOrFail($partId);
            $parts->delete($job, $part);
            $this->refreshParts($job);
        });

        return redirect(route('jobs.show', $job).'#spare-parts')->with('success', 'Part removed and its stock or expense reversed.');
    }

    private function stockPartRules(): array
    {
        return [
            'product_id' => 'required|exists:products,id', 'store_id' => 'required|exists:stores,id',
            'quantity' => 'required|numeric|decimal:0,2|min:0.01|max:999999.99',
            'unit_price' => 'required|numeric|decimal:0,2|min:0|max:99999999.99',
            'discount' => 'nullable|numeric|decimal:0,2|min:0|max:99999999.99',
            'stock_batch_id' => 'nullable|exists:stock_batches,id', 'note' => 'nullable|string|max:255',
        ];
    }

    private function refreshParts(JobCard $job): void
    {
        $job->update(['parts_total' => $job->parts()->sum('total')]);
        $this->syncInvoice($job);
    }

    public function invoice(Request $r, JobCard $job, WorkshopService $service)
    {
        $dates = $r->validate(['bill_date' => 'sometimes|required|date_format:Y-m-d|after_or_equal:1900-01-01|before_or_equal:today', 'paid_at' => 'sometimes|required|date_format:Y-m-d|after_or_equal:1900-01-01|before_or_equal:today']);
        $labels = $r->validate([
            'show_previous_balance_on_bill' => 'sometimes|required|boolean',
            'include_previous_balance' => 'sometimes|required|boolean',
            'service_name' => 'sometimes|required|string|max:50',
            'other_income_name' => 'sometimes|required|string|max:50',
        ]);
        $repair = $r->validate([
            'staff_repair_payment' => 'nullable|in:normal,salary',
            'repair_deduction_type' => 'nullable|required_if:staff_repair_payment,salary|in:Full deduction,Partial deduction,Monthly installment',
            'repair_installment_amount' => 'nullable|numeric|gt:0|decimal:0,2',
            'repair_start_month' => 'nullable|required_if:staff_repair_payment,salary|date_format:Y-m',
        ]);
        $salaryRepair = ($repair['staff_repair_payment'] ?? 'normal') === 'salary';
        if ($salaryRepair && ! $job->repair_staff) {
            throw ValidationException::withMessages(['staff_repair_payment' => 'Salary recovery is only available for a staff-owned bike repair.']);
        }
        unset($labels['show_previous_balance_on_bill'], $labels['include_previous_balance']);
        $settlement = app(SettlementService::class);
        $input = $r->all();
        $input['account_id'] ??= Account::systemAccount('main')->id;
        $rows = $settlement->rows($input);
        $invoice = DB::transaction(function () use ($r, $job, $service, $settlement, $rows, $labels, $salaryRepair, $repair, $dates) {
            if ($salaryRepair && $job->repair_staff) {
                Technician::whereKey($job->repair_staff->id)->lockForUpdate()->firstOrFail();
            }
            $job = JobCard::lockForUpdate()->findOrFail($job->id);
            if (! empty($dates['bill_date'])) {
                $job->update(['bill_date' => $dates['bill_date']]);
            }
            if ($labels) {
                $job->update($labels);
            }
            if ($r->has('show_previous_balance_on_bill')) {
                $job->update(['show_previous_balance_on_bill' => $r->boolean('show_previous_balance_on_bill')]);
            }

            $invoice = $service->invoice($job, $settlement->total($rows), count($rows) > 1 ? 'Mixed' : ($rows[0]['method'] ?? 'Credit/Due'), null, $rows, $r->parts_technician_id, ! $salaryRepair && $r->boolean('include_previous_balance', true), $dates['paid_at'] ?? null);
            if ($salaryRepair) {
                app(StaffBikeRepairService::class)->createPlan($job, [
                    'deduction_type' => $repair['repair_deduction_type'],
                    'installment_amount' => $repair['repair_installment_amount'] ?? null,
                    'start_month' => $repair['repair_start_month'],
                ]);
                $invoice->update(['payment_method' => $rows ? 'Mixed' : 'Salary Deduction']);
            }

            return $invoice;
        });

        return redirect()->route('jobs.show', $job)->with([
            'success' => 'Invoice generated.',
            'print_invoice' => $invoice->public_token,
            'invoice_id' => $invoice->id,
        ]);
    }

    public function unlock(Request $r, JobCard $job)
    {
        $v = $r->validate([
            'status' => 'required|in:In Progress,Diagnosis,Waiting for Parts,On Hold,Repair Ready,Ready for Delivery',
            'reason' => 'required|string|max:500',
        ]);

        $old = $job->status;
        $job->update(['status' => $v['status']]);

        DB::table('job_card_status_logs')->insert([
            'job_card_id' => $job->id,
            'old_status' => $old,
            'new_status' => $v['status'],
            'note' => '[Unlocked] '.$v['reason'],
            'changed_by' => auth()->id(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->syncInvoice($job);

        return back()->with('success', 'Job card unlocked and status set to '.$v['status']);
    }

    private function syncInvoice(JobCard $job)
    {
        $job->loadMissing('invoice');
        if ($job->invoice) {
            $invoice = Invoice::lockForUpdate()->findOrFail($job->invoice->id);
            if ($invoice->status === 'Cancelled') {
                return;
            }
            $total = $job->total;
            $subtotal = (float) $job->parts_total + (float) $job->service_total + (float) $job->labor_total + (float) $job->washing_total + (float) $job->other_total;
            $paid = (float) $invoice->paid_amount;
            if ($total < $paid + (float) $invoice->hold_amount) {
                throw ValidationException::withMessages(['total' => 'Cancel or refund payments before reducing the invoice below its settled amount.']);
            }
            $due = max(0, $total - $paid - (float) $invoice->hold_amount);

            $invoice->update([
                'subtotal' => $subtotal,
                'discount' => $job->discount,
                'tax' => $job->tax,
                'parts_amount' => $job->parts_total,
                'service_amount' => $job->service_total,
                'labor_amount' => $job->labor_total,
                'washing_amount' => $job->washing_total,
                'other_amount' => $job->other_total,
                'total' => $total,
                'due_amount' => $due,
                'status' => $paid >= $total ? 'Paid' : ($paid > 0 ? 'Partially Paid' : 'Unpaid'),
            ]);
            app(SettlementService::class)->saveStatus($invoice);
            app(AccountService::class)->reallocateInvoiceReceipts($invoice);
            app(JobCommissionService::class)->syncPending($job);
            app(WashingCommissionService::class)->sync($job);
        }
    }

    private static function statuses(): array
    {
        return ['Received', 'Assigned', 'Diagnosis', 'In Progress', 'Waiting for Parts', 'Washing / Final Check', 'On Hold', 'Repair Ready', 'Ready for Delivery', 'Pending Payment', 'Completed', 'Billed', 'Delivered', 'Cancelled'];
    }
}
