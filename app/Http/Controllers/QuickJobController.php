<?php

namespace App\Http\Controllers;

use App\Models\Bike;
use App\Models\Customer;
use App\Models\JobCard;
use App\Models\Technician;
use App\Services\OpeningBalanceService;
use App\Services\WashingCommissionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class QuickJobController extends Controller
{
    public function create()
    {
        return view('jobs.quick-create', ['technicians' => Technician::where('active', true)->orderBy('name')->get()]);
    }

    public function store(Request $request)
    {
        $data = $request->validate(OpeningBalanceService::rules() + [
            'customer_name' => 'nullable|string|max:150', 'phone' => 'nullable|string|max:30',
            'nic' => 'nullable|string|max:30', 'address' => 'nullable|string|max:1000', 'email' => 'nullable|email|max:255',
            'bike_number' => 'nullable|string|max:30', 'bike_model' => 'nullable|string|max:100',
            'brand' => 'nullable|string|max:100', 'color' => 'nullable|string|max:100',
            'chassis_number' => 'nullable|string|max:100', 'engine_number' => 'nullable|string|max:100',
            'current_mileage' => 'nullable|integer|min:0', 'complaint' => 'nullable|string|max:5000',
            'main_technician_id' => ['nullable', Rule::exists('technicians', 'id')->where('active', true)->whereNull('deleted_at')],
            'is_technician_bike' => 'nullable|boolean',
            'technician_id' => ['nullable', 'required_if:is_technician_bike,1', Rule::exists('technicians', 'id')->where('active', true)->whereNull('deleted_at')],
            'service_total' => 'nullable|numeric|min:0|max:999999999999.99',
            'labor_total' => 'nullable|numeric|min:0|max:999999999999.99',
            'washing_total' => 'nullable|numeric|min:0|max:999999999999.99',
        ] + WashingCommissionService::rules());
        $data += app(WashingCommissionService::class)->defaults();

        $job = DB::transaction(function () use ($data, $request) {
            $number = strtoupper(trim($data['bike_number'] ?? ''));
            if ($number !== '' && Bike::onlyTrashed()->where('number', $number)->exists()) {
                throw ValidationException::withMessages(['bike_number' => 'This registration belongs to an archived bike. Restore it before adding a job.']);
            }
            $bike = $number !== '' ? Bike::with('customer')->where('number', $number)->lockForUpdate()->first() : null;
            if ($bike && ! $bike->customer) {
                throw ValidationException::withMessages(['bike_number' => 'This bike is not available in the current shop.']);
            }
            $customer = $bike?->customer;
            if (! $customer && ! empty($data['phone'])) {
                $customer = Customer::where('phone', $data['phone'])->first();
            }
            if (! $customer && ! empty($data['nic'])) {
                $customer = Customer::where('nic', $data['nic'])->first();
            }
            $customerFields = array_filter([
                'name' => $data['customer_name'] ?? null, 'phone' => $data['phone'] ?? null,
                'nic' => $data['nic'] ?? null, 'address' => $data['address'] ?? null, 'email' => $data['email'] ?? null,
            ], fn ($value) => $value !== null && $value !== '');
            if ($customer) {
                $customer->update($customerFields);
            } else {
                $customer = Customer::create($customerFields + ['phone' => '']);
            }
            $bikeFields = array_filter([
                'model' => $data['bike_model'] ?? null, 'brand' => $data['brand'] ?? null,
                'color' => $data['color'] ?? null, 'chassis_number' => $data['chassis_number'] ?? null,
                'engine_number' => $data['engine_number'] ?? null, 'last_mileage' => $data['current_mileage'] ?? null,
            ], fn ($value) => $value !== null && $value !== '');
            if ($request->boolean('is_technician_bike')) {
                $bikeFields += ['is_technician_bike' => true, 'technician_id' => $data['technician_id']];
            }
            if ($bike) {
                $bike->update($bikeFields);
            } elseif ($number !== '') {
                $bike = Bike::create($bikeFields + ['customer_id' => $customer->id, 'number' => $number, 'model' => '']);
            }

            $job = JobCard::create([
                'is_quick_job' => true, 'quick_customer_id' => $customer->id, 'quick_bike_id' => $bike?->id,
                'quick_bike_details' => $bikeFields, 'quick_complaint' => $data['complaint'] ?? null,
                'is_staff_bike' => $request->boolean('is_technician_bike') || (bool) $bike?->is_technician_bike,
                'staff_bike_owner_id' => $request->boolean('is_technician_bike') ? $data['technician_id'] : $bike?->technician_id,
                'main_technician_id' => $data['main_technician_id'] ?? null,
                'washing_person_id' => $data['washing_person_id'], 'washing_commission_amount' => $data['washing_commission_amount'],
                'number' => 'JOB-'.now()->format('ymd').'-'.Str::upper(Str::random(6)),
                'status' => ! empty($data['main_technician_id']) ? 'Assigned' : 'Received', 'priority' => 'Normal',
                'service_total' => $data['service_total'] ?? 0, 'labor_total' => $data['labor_total'] ?? 0,
                'washing_total' => $data['washing_total'] ?? 0, 'created_by' => $request->user()->id,
            ]);
            app(OpeningBalanceService::class)->record($customer, $data, $bike);
            DB::table('job_card_status_logs')->insert([
                'job_card_id' => $job->id, 'new_status' => $job->status,
                'note' => 'Quick job created without a bike intake.', 'changed_by' => $request->user()->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            return $job;
        });

        return redirect()->route('jobs.show', $job)->with('success', 'Quick job created.');
    }
}
