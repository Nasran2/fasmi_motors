<?php

namespace App\Http\Controllers;

use App\Exports\CustomerExport;
use App\Imports\CustomerImport;
use App\Models\Account;
use App\Models\Bike;
use App\Models\BikeIntake;
use App\Models\BikeOwnershipChange;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\JobCard;
use App\Models\Payment;
use App\Models\Technician;
use App\Services\OpeningBalanceService;
use App\Services\SettlementService;
use App\Services\WorkshopService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;

class CustomerBikeController extends Controller
{
    public function customers(Request $request)
    {
        $query = Customer::query()->withCount('bikes')
            ->selectSub($this->customerJobsSubquery(), 'jobs_count')
            ->selectSub($this->customerDueSubquery(), 'due_amount')
            ->selectSub($this->customerDueSubquery('hold_amount'), 'hold_amount')
            ->selectSub($this->customerLastVisitSubquery(), 'last_visit')
            ->when($request->q, fn ($q, $term) => $q->where(fn ($x) => $x->where('name', 'like', "%{$term}%")->orWhere('phone', 'like', "%{$term}%")->orWhereHas('bikes', fn ($b) => $b->where('number', 'like', "%{$term}%"))))
            ->when($request->filter === 'due', fn ($q) => $q->whereExists($this->customerDueExistsSubquery()))
            ->when($request->filter === 'service', fn ($q) => $q->whereHas('bikes.intakes', fn ($i) => $i->where('is_service', true)))
            ->when($request->status === 'active', fn ($q) => $q->where('active', true))
            ->when($request->status === 'inactive', fn ($q) => $q->where('active', false))
            ->when($request->from, fn ($q, $date) => $q->whereDate('created_at', '>=', $date))
            ->when($request->to, fn ($q, $date) => $q->whereDate('created_at', '<=', $date))
            ->latest();

        return view('customers.index', [
            'customers' => $query->paginate(15)->withQueryString(),
            'accounts' => Account::where('active', true)->get(),
            'stats' => [
                'total' => Customer::count(), 'active' => Customer::where('active', true)->count(),
                'due' => Customer::whereExists($this->customerDueExistsSubquery())->count(),
                'service' => Customer::whereHas('bikes.intakes', fn ($q) => $q->where('is_service', true))->count(),
            ],
        ]);
    }

    public function customerCreate()
    {
        return view('customers.create');
    }

    public function customerStore(Request $request)
    {
        $data = $request->validate(OpeningBalanceService::rules() + [
            'name' => ['required', 'max:150'], 'phone' => ['required', 'max:30'], 'alternative_phone' => ['nullable', 'max:30'],
            'address' => ['nullable', 'max:500'], 'nic' => ['nullable', 'max:30'], 'email' => ['nullable', 'email', 'max:150'],
            'customer_type' => ['required', Rule::in(['Normal Customer', 'Regular Customer', 'Credit Customer', 'Technician Bike Owner'])],
            'active' => ['required', 'boolean'], 'bike_number' => ['nullable', 'max:30', function ($attribute, $value, $fail) {
                $bike = Bike::with('customer')->where('number', $value)->first();
                if ($bike) {
                    $name = $bike->customer->name ?: 'Walk-in Customer';
                    $fail("This bike ({$bike->model}) is already registered to {$name} ({$bike->customer->phone}).");
                }
            }],
            'bike_model' => ['nullable', 'required_with:bike_number', 'max:100'], 'bike_brand' => ['nullable', 'max:100'],
            'bike_color' => ['nullable', 'max:50'], 'chassis_number' => ['nullable', 'max:100'], 'engine_number' => ['nullable', 'max:100'],
            'current_km' => ['nullable', 'integer', 'min:0'],
        ]);

        [$customer, $bike] = DB::transaction(function () use ($data) {
            $customer = Customer::create(collect($data)->only(['name', 'phone', 'alternative_phone', 'address', 'nic', 'email', 'customer_type', 'active'])->all());
            $bike = null;
            if (! empty($data['bike_number'])) {
                $bike = Bike::create([
                    'customer_id' => $customer->id, 'number' => strtoupper($data['bike_number']), 'model' => $data['bike_model'], 'brand' => $data['bike_brand'] ?? null,
                    'color' => $data['bike_color'] ?? null, 'chassis_number' => $data['chassis_number'] ?? null,
                    'engine_number' => $data['engine_number'] ?? null, 'last_mileage' => $data['current_km'] ?? null,
                ]);
            }
            app(OpeningBalanceService::class)->record($customer, $data, $bike);

            return [$customer, $bike];
        });

        return match ($request->input('action')) {
            'add_bike' => redirect()->route('customers.bikes.create', ['customer' => $customer->id])->with('success', 'Customer saved. Add their bike.'),
            'create_intake' => $bike instanceof Bike
                ? redirect()->route('intakes.create', ['bike' => $bike->number])->with('success', 'Customer and bike saved. Create the intake.')
                : redirect()->route('customers.bikes.create', ['customer' => $customer->id, 'next' => 'intake'])->with('success', 'Customer saved. Add a bike before creating an intake.'),
            default => redirect()->route('customers.show', $customer)->with('success', 'Customer created.'),
        };
    }

    public function customerShow(Customer $customer)
    {
        $customer->load([
            'bikes' => fn ($query) => $query->select('bikes.*')->selectSub($this->bikeDueSubquery()->customerAccount($customer->id), 'due_amount'),
            'bikes.intakes',
        ]);

        $billQuery = Invoice::customerAccount($customer->id)->where('invoice_type', '!=', 'Quotation');
        $dueAmount = (clone $billQuery)->where('status', '!=', 'Cancelled')->sum('due_amount');
        $bills = $billQuery->with(['bike', 'jobCard.intake.bike'])->latest('created_at')->latest('id')
            ->paginate(20, ['*'], 'bills_page')->withQueryString()->fragment('customer-bills');
        $accounts = Account::where('active', true)->get();

        return view('customers.show', compact('customer', 'dueAmount', 'bills', 'accounts'));
    }

    public function customerUpdate(Request $request, Customer $customer)
    {
        $customer->update($request->validate(['name' => ['required', 'max:150'], 'phone' => ['required', 'max:30'], 'alternative_phone' => ['nullable', 'max:30'], 'address' => ['nullable', 'max:500'], 'nic' => ['nullable', 'max:30'], 'email' => ['nullable', 'email', 'max:150'], 'customer_type' => ['required', 'max:50'], 'active' => ['required', 'boolean']]));

        return back()->with('success', 'Customer updated.');
    }

    public function customerDeactivate(Customer $customer)
    {
        $customer->update(['active' => false]);

        return back()->with('success', 'Customer deactivated. Existing history was preserved.');
    }

    public function bikes(Request $request)
    {
        $query = Bike::query()->select('bikes.*')->with(['customer', 'technician'])
            ->selectSub($this->bikeDueSubquery(), 'due_amount')
            ->selectSub(BikeIntake::selectRaw('max(created_at)')->whereColumn('bike_id', 'bikes.id'), 'last_job_date')
            ->selectSub(BikeIntake::select('next_service_date')->whereColumn('bike_id', 'bikes.id')->whereNotNull('next_service_date')->latest('created_at')->limit(1), 'next_service_date')
            ->when($request->q, fn ($q, $term) => $q->where(fn ($x) => $x->where('number', 'like', "%{$term}%")->orWhere('model', 'like', "%{$term}%")->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$term}%")->orWhere('phone', 'like', "%{$term}%"))))
            ->when($request->filter === 'service_due', fn ($q) => $q->whereHas('intakes', fn ($i) => $i->whereDate('next_service_date', '<=', today())))
            ->when($request->filter === 'due', fn ($q) => $q->whereExists($this->bikeDueExistsSubquery()))
            ->when($request->filter === 'technician', fn ($q) => $q->where('is_technician_bike', true))
            ->latest();

        return view('customers.bikes', [
            'bikes' => $query->paginate(15)->withQueryString(),
            'stats' => ['total' => Bike::count(), 'service_due' => Bike::whereHas('intakes', fn ($q) => $q->whereDate('next_service_date', '<=', today()))->count(), 'due' => Bike::whereExists($this->bikeDueExistsSubquery())->count(), 'technician' => Bike::where('is_technician_bike', true)->count()],
        ]);
    }

    public function bikeCreate(Request $request)
    {
        return view('customers.bike-create', ['customers' => Customer::where('active', true)->orderBy('name')->get(), 'technicians' => Technician::where('active', true)->orderBy('name')->get(), 'selectedCustomer' => $request->customer, 'next' => $request->next]);
    }

    public function bikeStore(Request $request)
    {
        $data = $request->validate([
            'number' => ['required', 'max:30', function ($attribute, $value, $fail) {
                $bike = Bike::with('customer')->where('number', $value)->first();
                if ($bike) {
                    $name = $bike->customer->name ?: 'Walk-in Customer';
                    $fail("This bike ({$bike->model}) is already registered to {$name} ({$bike->customer->phone}).");
                }
            }], 'model' => ['required', 'max:100'], 'brand' => ['nullable', 'max:100'],
            'color' => ['nullable', 'max:50'], 'chassis_number' => ['nullable', 'max:100'], 'engine_number' => ['nullable', 'max:100'],
            'last_mileage' => ['nullable', 'integer', 'min:0'], 'previous_mileage' => ['nullable', 'integer', 'min:0'],
            'fuel_type' => ['nullable', 'max:30'], 'notes' => ['nullable', 'max:1000'],
            'customer_mode' => ['required', Rule::in(['existing', 'new'])], 'customer_id' => ['nullable', 'required_if:customer_mode,existing', 'exists:customers,id'],
            'customer_name' => ['nullable', 'required_if:customer_mode,new', 'max:150'], 'customer_phone' => ['nullable', 'required_if:customer_mode,new', 'max:30'],
            'customer_address' => ['nullable', 'max:500'], 'is_technician_bike' => ['nullable', 'boolean'],
            'technician_id' => ['nullable', 'required_if:is_technician_bike,1', 'exists:technicians,id'], 'deduction_note' => ['nullable', 'max:500'],
        ]);

        $bike = DB::transaction(function () use ($data, $request) {
            $customer = $data['customer_mode'] === 'existing'
                ? Customer::findOrFail($data['customer_id'])
                : Customer::create(['name' => $data['customer_name'], 'phone' => $data['customer_phone'], 'address' => $data['customer_address'] ?? null, 'customer_type' => 'Normal Customer', 'active' => true]);

            return Bike::create([
                'customer_id' => $customer->id, 'number' => strtoupper($data['number']), 'model' => $data['model'], 'brand' => $data['brand'] ?? null, 'color' => $data['color'] ?? null,
                'chassis_number' => $data['chassis_number'] ?? null, 'engine_number' => $data['engine_number'] ?? null,
                'last_mileage' => $data['last_mileage'] ?? null, 'previous_mileage' => $data['previous_mileage'] ?? null,
                'fuel_type' => $data['fuel_type'] ?? null, 'notes' => trim(($data['notes'] ?? '').($data['deduction_note'] ?? '' ? "\nDeduction: ".$data['deduction_note'] : '')) ?: null,
                'is_technician_bike' => $request->boolean('is_technician_bike'), 'technician_id' => $data['technician_id'] ?? null,
            ]);
        });

        return $request->input('action') === 'intake' || $request->input('next') === 'intake'
            ? redirect()->route('intakes.create', ['bike' => $bike->number])->with('success', 'Bike registered. Create the intake.')
            : redirect()->route('customers.bikes.show', $bike)->with('success', 'Bike registered.');
    }

    public function bikeShow(Bike $bike)
    {
        $bike->load('customer', 'technician', 'intakes.jobCard.technician', 'intakes.jobCard.invoice');

        return view('customers.bike-show', compact('bike'));
    }

    public function dues(Request $request)
    {
        $query = Invoice::query()->with(['customer', 'bike.customer', 'jobCard.intake.bike.customer', 'payments'])
            ->where('status', '!=', 'Cancelled')->where('due_amount', '>', 0)
            ->when($request->q, fn ($q, $term) => $q->where(fn ($x) => $x->where('number', 'like', "%{$term}%")->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$term}%")->orWhere('phone', 'like', "%{$term}%"))->orWhereHas('jobCard.intake.bike', fn ($b) => $b->where('number', 'like', "%{$term}%"))))
            ->when($request->from, fn ($q, $date) => $q->whereDate('due_date', '>=', $date))
            ->when($request->to, fn ($q, $date) => $q->whereDate('due_date', '<=', $date))
            ->when($request->minimum, fn ($q, $value) => $q->where('due_amount', '>=', $value))
            ->when($request->status === 'overdue', fn ($q) => $q->whereDate('due_date', '<', today()))
            ->latest();

        return view('customers.dues', [
            'invoices' => $query->paginate(15)->withQueryString(), 'accounts' => Account::where('active', true)->get(),
            'stats' => ['total' => Invoice::where('due_amount', '>', 0)->sum('due_amount'), 'customers' => Invoice::where('due_amount', '>', 0)->distinct()->count(DB::raw('coalesce(customer_id, job_card_id)')), 'overdue' => Invoice::where('due_amount', '>', 0)->whereDate('due_date', '<', today())->sum('due_amount'), 'collections' => Payment::where('status', 'Completed')->whereDate('paid_at', today())->sum('amount')],
        ]);
    }

    public function receivePayment(Request $request, Invoice $invoice, WorkshopService $service)
    {
        $validated = $request->validate(['paid_at' => 'nullable|date_format:Y-m-d|before_or_equal:today']);
        $paidAt = ! empty($validated['paid_at']) ? Carbon::parse($validated['paid_at'])->startOfDay() : now();
        $settlement = app(SettlementService::class);
        $rows = $settlement->rows($request->all());
        DB::transaction(function () use ($rows, $invoice, $settlement, $paidAt) {
            $invoice = Invoice::lockForUpdate()->findOrFail($invoice->id);
            if (! $rows || $invoice->status === 'Cancelled' || (float) $invoice->due_amount <= 0) {
                throw ValidationException::withMessages(['amount' => 'Select an invoice with an outstanding due and enter a payment.']);
            }
            $nonCashRows = array_values(array_filter($rows, fn ($row) => $row['method'] !== 'Cash'));
            $cashRows = array_values(array_filter($rows, fn ($row) => $row['method'] === 'Cash'));
            if ($settlement->total($nonCashRows) > round((float) $invoice->due_amount, 2)) {
                throw ValidationException::withMessages(['amount' => 'Non-cash payments cannot exceed the remaining due. Enter excess cash as Cash to calculate change.']);
            }
            foreach (array_merge($nonCashRows, $cashRows) as $row) {
                $settlement->receive($invoice, $row, $paidAt, allowCashChange: true);
            }
        });

        return back()->with('success', 'Payments received. Cheques are on hold until passed.');
    }

    public function serviceHistory(Request $request)
    {
        $jobs = JobCard::query()->with(['intake.bike.customer', 'technician', 'invoice'])
            ->when($request->q, fn ($q, $term) => $q->where(fn ($x) => $x->where('number', 'like', "%{$term}%")->orWhereHas('intake.bike', fn ($b) => $b->where('number', 'like', "%{$term}%")->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$term}%")->orWhere('phone', 'like', "%{$term}%")))))
            ->when($request->type === 'service', fn ($q) => $q->whereHas('intake', fn ($i) => $i->where('is_service', true)))
            ->when($request->type === 'repair', fn ($q) => $q->whereHas('intake', fn ($i) => $i->where('is_service', false)))
            ->when($request->status, fn ($q, $status) => $q->where('status', $status))
            ->when($request->technician, fn ($q, $id) => $q->where('main_technician_id', $id))
            ->when($request->from, fn ($q, $date) => $q->whereDate('created_at', '>=', $date))
            ->when($request->to, fn ($q, $date) => $q->whereDate('created_at', '<=', $date))->latest();

        return view('customers.service-history', ['jobs' => $jobs->paginate(15)->withQueryString(), 'technicians' => Technician::where('active', true)->get(), 'reminders' => BikeIntake::with('bike.customer')->whereNotNull('next_service_date')->orderBy('next_service_date')->limit(8)->get()]);
    }

    public function ownership()
    {
        $bikes = Bike::with('customer')->orderBy('number')->get();
        $customers = Customer::where('active', true)->orderBy('name')->get();

        return view('customers.ownership', [
            'bikes' => $bikes,
            'customers' => $customers,
            'bikeSearchOptions' => $bikes->map(fn (Bike $bike) => [
                'id' => $bike->id,
                'label' => $bike->number.' — '.$bike->model.' — '.($bike->customer->name ?: $bike->customer->phone),
                'owner' => $bike->customer->name ?: 'Walk-in',
                'phone' => $bike->customer->phone,
                'model' => $bike->model,
            ])->values(),
            'customerSearchOptions' => $customers->map(fn (Customer $customer) => [
                'id' => $customer->id,
                'label' => ($customer->name ?: 'Walk-in').' — '.$customer->phone,
            ])->values(),
            'changes' => BikeOwnershipChange::with(['bike', 'oldCustomer', 'newCustomer', 'changedBy'])->latest('changed_at')->paginate(15),
        ]);
    }

    public function ownershipStore(Request $request)
    {
        $request->merge(['owner_mode' => $request->input('owner_mode', 'existing')]);
        $data = $request->validate([
            'bike_id' => ['required', 'exists:bikes,id'],
            'owner_mode' => ['required', Rule::in(['existing', 'new'])],
            'new_customer_id' => ['exclude_unless:owner_mode,existing', 'required', 'exists:customers,id'],
            'new_name' => ['exclude_unless:owner_mode,new', 'required', 'string', 'max:150'],
            'new_phone' => ['exclude_unless:owner_mode,new', 'required', 'string', 'max:30'],
            'new_address' => ['exclude_unless:owner_mode,new', 'nullable', 'string', 'max:500'],
            'changed_at' => ['required', 'date'],
            'due_handling' => ['required', Rule::in(['keep_old', 'transfer_new', 'clear'])],
            'reason' => ['nullable', 'required_if:due_handling,clear', 'max:1000'],
        ]);
        DB::transaction(function () use ($data) {
            $bike = Bike::with('customer', 'intakes.jobCard.invoice')->lockForUpdate()->findOrFail($data['bike_id']);
            if ($data['owner_mode'] === 'new') {
                $customer = Customer::create([
                    'name' => $data['new_name'],
                    'phone' => $data['new_phone'],
                    'address' => $data['new_address'] ?? null,
                    'customer_type' => 'Normal Customer',
                    'active' => true,
                ]);
                $data['new_customer_id'] = $customer->id;
            }
            if ($bike->customer_id === (int) $data['new_customer_id']) {
                throw ValidationException::withMessages(['new_customer_id' => 'Select a different owner.']);
            }
            $dueInvoices = Invoice::whereHas('jobCard.intake', fn ($query) => $query->where('bike_id', $bike->id))->where('due_amount', '>', 0)->get();
            $dueAmount = (float) $dueInvoices->sum('due_amount');
            foreach ($dueInvoices as $invoice) {
                if ($data['due_handling'] === 'transfer_new') {
                    $invoice->update(['customer_id' => $data['new_customer_id']]);
                } elseif ($data['due_handling'] === 'keep_old') {
                    $invoice->update(['customer_id' => $bike->customer_id]);
                } else {
                    $invoice->payments()->create(['amount' => $invoice->due_amount, 'method' => 'Manual Clearance', 'reference' => $data['reason'], 'paid_at' => now(), 'created_by' => auth()->id()]);
                    $invoice->update(['paid_amount' => (float) $invoice->paid_amount + (float) $invoice->due_amount, 'due_amount' => 0, 'customer_id' => $bike->customer_id]);
                    app(SettlementService::class)->saveStatus($invoice);
                }
            }
            BikeOwnershipChange::create(['bike_id' => $bike->id, 'old_customer_id' => $bike->customer_id, 'new_customer_id' => $data['new_customer_id'], 'changed_at' => $data['changed_at'], 'due_handling' => $data['due_handling'], 'due_amount' => $dueAmount, 'reason' => $data['reason'] ?? null, 'changed_by' => auth()->id()]);
            $bike->update(['customer_id' => $data['new_customer_id']]);
        });

        return back()->with('success', 'Bike ownership changed. Repair history remains attached to the bike.');
    }

    private function customerJobsSubquery()
    {
        return JobCard::selectRaw('count(*)')->join('bike_intakes', 'bike_intakes.id', '=', 'job_cards.bike_intake_id')->join('bikes', 'bikes.id', '=', 'bike_intakes.bike_id')->whereColumn('bikes.customer_id', 'customers.id');
    }

    private function customerDueSubquery(string $column = 'due_amount')
    {
        return Invoice::selectRaw("coalesce(sum(invoices.{$column}),0)")->where('invoices.status', '!=', 'Cancelled')->where('invoices.invoice_type', '!=', 'Quotation')->leftJoin('job_cards', 'job_cards.id', '=', 'invoices.job_card_id')->leftJoin('bike_intakes', 'bike_intakes.id', '=', 'job_cards.bike_intake_id')->leftJoin('bikes', 'bikes.id', '=', 'bike_intakes.bike_id')->where(fn ($q) => $q->whereColumn('invoices.customer_id', 'customers.id')->orWhere(fn ($x) => $x->whereNull('invoices.customer_id')->whereColumn('bikes.customer_id', 'customers.id')));
    }

    private function customerLastVisitSubquery()
    {
        return BikeIntake::selectRaw('max(bike_intakes.created_at)')->join('bikes', 'bikes.id', '=', 'bike_intakes.bike_id')->whereColumn('bikes.customer_id', 'customers.id');
    }

    private function customerDueExistsSubquery()
    {
        return Invoice::selectRaw('1')->where('invoices.status', '!=', 'Cancelled')->where('invoices.invoice_type', '!=', 'Quotation')->leftJoin('job_cards', 'job_cards.id', '=', 'invoices.job_card_id')->leftJoin('bike_intakes', 'bike_intakes.id', '=', 'job_cards.bike_intake_id')->leftJoin('bikes', 'bikes.id', '=', 'bike_intakes.bike_id')->where('invoices.due_amount', '>', 0)->where(fn ($q) => $q->whereColumn('invoices.customer_id', 'customers.id')->orWhere(fn ($x) => $x->whereNull('invoices.customer_id')->whereColumn('bikes.customer_id', 'customers.id')));
    }

    private function bikeDueSubquery()
    {
        return Invoice::selectRaw('coalesce(sum(invoices.due_amount),0)')->where('invoices.status', '!=', 'Cancelled')->where('invoices.invoice_type', '!=', 'Quotation')->leftJoin('job_cards', 'job_cards.id', '=', 'invoices.job_card_id')->leftJoin('bike_intakes', 'bike_intakes.id', '=', 'job_cards.bike_intake_id')->where(fn ($q) => $q->whereColumn('invoices.bike_id', 'bikes.id')->orWhere(fn ($q) => $q->whereNull('invoices.bike_id')->whereColumn('bike_intakes.bike_id', 'bikes.id')));
    }

    private function bikeDueExistsSubquery()
    {
        return Invoice::selectRaw('1')->where('invoices.status', '!=', 'Cancelled')->where('invoices.invoice_type', '!=', 'Quotation')->leftJoin('job_cards', 'job_cards.id', '=', 'invoices.job_card_id')->leftJoin('bike_intakes', 'bike_intakes.id', '=', 'job_cards.bike_intake_id')->where(fn ($q) => $q->whereColumn('invoices.bike_id', 'bikes.id')->orWhere(fn ($q) => $q->whereNull('invoices.bike_id')->whereColumn('bike_intakes.bike_id', 'bikes.id')))->where('invoices.due_amount', '>', 0);
    }

    public function export()
    {
        return Excel::download(new CustomerExport, 'customers.xlsx');
    }

    public function import(Request $request)
    {
        $request->validate([
            'file' => 'required|mimes:xlsx,csv,xls',
        ]);

        Excel::import(new CustomerImport, $request->file('file'));

        return back()->with('success', 'Customers imported successfully!');
    }
}
