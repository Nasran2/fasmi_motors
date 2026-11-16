<?php

namespace App\Http\Controllers;

use App\Models\Bike;
use App\Models\BikeIntake;
use App\Models\Customer;
use App\Models\Technician;
use App\Services\OpeningBalanceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BikeIntakeController extends Controller
{
    public function index(Request $r)
    {
        $q = BikeIntake::with('bike.customer', 'jobCard')->latest('intake_date')->latest();
        if ($r->q) {
            $term = trim($r->q);
            $q->where(function ($query) use ($term) {
                $query->where('intake_number', 'like', '%'.$term.'%')
                    ->orWhereHas('bike', fn ($x) => $x->where('number', 'like', '%'.$term.'%'))
                    ->orWhereHas('bike.customer', fn ($x) => $x->where('name', 'like', '%'.$term.'%')->orWhere('phone', 'like', '%'.$term.'%'));
            });
        }

        return view('intakes.index', ['intakes' => $q->paginate(15)]);
    }

    public function create()
    {
        return view('intakes.create', ['technicians' => Technician::where('active', true)->get()]);
    }

    public function lookup(string $query)
    {
        $term = trim($query);

        $bikes = Bike::with('customer', 'technician', 'intakes.jobCard.invoice')
            ->withSum('invoices as total_due', 'due_amount')
            ->where('number', 'like', "%{$term}%")
            ->orWhereHas('customer', function ($q) use ($term) {
                $q->where('name', 'like', "%{$term}%")
                    ->orWhere('phone', 'like', "%{$term}%");
            })
            ->limit(10)
            ->get();

        $customers = Customer::doesntHave('bikes')
            ->where(function ($q) use ($term) {
                $q->where('name', 'like', "%{$term}%")
                    ->orWhere('phone', 'like', "%{$term}%");
            })
            ->limit(5)
            ->get();

        return response()->json([
            'bikes' => $bikes,
            'customers' => $customers,
        ]);
    }

    public function store(Request $r)
    {
        $v = $r->validate(OpeningBalanceService::rules() + ['intake_date' => 'sometimes|required|date_format:Y-m-d', 'bike_number' => 'required|max:30', 'bike_model' => 'required|max:100', 'chassis_number' => ['nullable', 'string', 'max:100'], 'phone' => 'nullable|string|max:30', 'complaint' => 'required', 'customer_name' => 'nullable|max:150', 'estimated_amount' => 'nullable|numeric|min:0', 'advance_payment' => 'nullable|numeric|min:0', 'current_mileage' => 'nullable|integer|min:0', 'next_service_km' => 'nullable|integer|min:0', 'next_service_date' => 'nullable|date', 'technician_id' => 'nullable|required_if:is_technician_bike,1|exists:technicians,id']);
        $intake = DB::transaction(function () use ($r, $v) {
            $phone = $v['phone'] ?? '';
            $bike = Bike::with('customer')->where('number', strtoupper($v['bike_number']))->first();
            if ($bike) {
                $bike->customer->update(['name' => $v['customer_name'] ?? $bike->customer->name, 'phone' => $phone !== '' ? $phone : $bike->customer->phone]);
                $bike->update(['model' => $v['bike_model'], 'chassis_number' => $v['chassis_number'] ?? $bike->chassis_number, 'brand' => $r->brand, 'color' => $r->color, 'last_mileage' => $r->current_mileage, 'is_technician_bike' => $r->boolean('is_technician_bike'), 'technician_id' => $r->technician_id]);
            } else {
                $customer = $phone !== '' ? Customer::where('phone', $phone)->first() : null;
                if ($customer) {
                    $customer->update(['name' => $v['customer_name'] ?? $customer->name, 'address' => $r->address ?? $customer->address, 'nic' => $r->nic ?? $customer->nic]);
                } else {
                    $customer = Customer::create(['name' => $v['customer_name'] ?? null, 'phone' => $phone, 'address' => $r->address, 'nic' => $r->nic]);
                }
                $bike = Bike::create(['customer_id' => $customer->id, 'number' => strtoupper($v['bike_number']), 'model' => $v['bike_model'], 'chassis_number' => $v['chassis_number'] ?? null, 'brand' => $r->brand, 'color' => $r->color, 'last_mileage' => $r->current_mileage, 'is_technician_bike' => $r->boolean('is_technician_bike'), 'technician_id' => $r->technician_id]);
            }

            app(OpeningBalanceService::class)->record($bike->customer, $v, $bike);

            $intakeDate = $v['intake_date'] ?? today()->toDateString();

            return $bike->intakes()->create([
                'intake_number' => BikeIntake::generateNumber($intakeDate),
                'intake_date' => $intakeDate,
                'complaint' => $v['complaint'],
                'current_mileage' => $r->current_mileage,
                'fuel_level' => $r->fuel_level,
                'condition_notes' => $r->condition_notes,
                'accessories' => $r->accessories,
                'estimated_delivery_date' => $r->estimated_delivery_date,
                'estimated_amount' => $r->estimated_amount ?? 0,
                'advance_payment' => $r->advance_payment ?? 0,
                'payment_method' => $r->payment_method,
                'is_service' => $r->boolean('is_service'),
                'next_service_km' => $r->next_service_km,
                'next_service_date' => $r->next_service_date,
                'created_by' => auth()->id(),
            ]);
        });

        return redirect()->route('intakes.show', $intake)->with('success', 'Bike intake recorded.');
    }

    public function show(BikeIntake $intake)
    {
        return view('intakes.show', ['intake' => $intake->load('bike.customer', 'bike.intakes.jobCard.invoice', 'jobCard.technician')]);
    }

    public function edit(BikeIntake $intake)
    {
        return view('intakes.edit', [
            'intake' => $intake->load('bike.customer'),
            'technicians' => Technician::where('active', true)->get(),
        ]);
    }

    public function update(Request $r, BikeIntake $intake)
    {
        $v = $r->validate(['intake_date' => 'sometimes|required|date_format:Y-m-d', 'bike_number' => 'required|max:30', 'bike_model' => 'required|max:100', 'chassis_number' => ['nullable', 'string', 'max:100'], 'phone' => 'nullable|string|max:30', 'complaint' => 'required', 'customer_name' => 'nullable|max:150', 'estimated_amount' => 'nullable|numeric|min:0', 'advance_payment' => 'nullable|numeric|min:0', 'current_mileage' => 'nullable|integer|min:0', 'next_service_km' => 'nullable|integer|min:0', 'next_service_date' => 'nullable|date', 'technician_id' => 'nullable|required_if:is_technician_bike,1|exists:technicians,id']);

        DB::transaction(function () use ($r, $v, $intake) {
            $phone = $v['phone'] ?? '';
            $bike = Bike::with('customer')->where('number', strtoupper($v['bike_number']))->first();
            if ($bike) {
                $bike->customer->update(['name' => $v['customer_name'] ?? $bike->customer->name, 'phone' => array_key_exists('phone', $v) ? $phone : $bike->customer->phone]);
                $bike->update(['model' => $v['bike_model'], 'chassis_number' => array_key_exists('chassis_number', $v) ? $v['chassis_number'] : $bike->chassis_number, 'brand' => $r->brand, 'color' => $r->color, 'last_mileage' => $r->current_mileage, 'is_technician_bike' => $r->boolean('is_technician_bike'), 'technician_id' => $r->technician_id]);
            } else {
                $customer = $phone !== '' ? Customer::where('phone', $phone)->first() : null;
                if ($customer) {
                    $customer->update(['name' => $v['customer_name'] ?? $customer->name, 'address' => $r->address ?? $customer->address, 'nic' => $r->nic ?? $customer->nic]);
                } else {
                    $customer = Customer::create(['name' => $v['customer_name'] ?? null, 'phone' => $phone, 'address' => $r->address, 'nic' => $r->nic]);
                }
                $bike = Bike::create(['customer_id' => $customer->id, 'number' => strtoupper($v['bike_number']), 'model' => $v['bike_model'], 'chassis_number' => $v['chassis_number'] ?? null, 'brand' => $r->brand, 'color' => $r->color, 'last_mileage' => $r->current_mileage, 'is_technician_bike' => $r->boolean('is_technician_bike'), 'technician_id' => $r->technician_id]);
            }

            $intake->update(['bike_id' => $bike->id, 'intake_date' => $v['intake_date'] ?? $intake->intake_date, 'complaint' => $v['complaint'], 'current_mileage' => $r->current_mileage, 'fuel_level' => $r->fuel_level, 'condition_notes' => $r->condition_notes, 'accessories' => $r->accessories, 'estimated_delivery_date' => $r->estimated_delivery_date, 'estimated_amount' => $r->estimated_amount ?? 0, 'advance_payment' => $r->advance_payment ?? 0, 'payment_method' => $r->payment_method, 'is_service' => $r->boolean('is_service'), 'next_service_km' => $r->next_service_km, 'next_service_date' => $r->next_service_date]);
        });

        return redirect()->route('intakes.show', $intake)->with('success', 'Bike intake updated.');
    }

    public function destroy(BikeIntake $intake)
    {
        if ($intake->jobCard) {
            return back()->with('error', 'Cannot delete intake because it already has a Job Card.');
        }

        $intake->delete();

        return redirect()->route('intakes.index')->with('success', 'Bike intake deleted successfully.');
    }
}
