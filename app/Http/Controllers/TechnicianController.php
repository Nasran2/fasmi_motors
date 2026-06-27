<?php

namespace App\Http\Controllers;

use App\Models\Technician;
use App\Models\TechnicianAttendance;
use Illuminate\Http\Request;

class TechnicianController extends Controller
{
    public function index()
    {
        return view('technicians.index', ['technicians' => Technician::withCount(['jobs', 'attendance'])->latest()->paginate(15)]);
    }

    public function store(Request $r)
    {
        $v = $r->validate(['name' => 'required|max:150', 'phone' => 'required|max:30', 'basic_salary' => 'required|numeric|min:0', 'commission_percentage' => 'required|numeric|min:0|max:100', 'ot_rate' => 'nullable|numeric|min:0', 'joining_date' => 'nullable|date']);
        Technician::create($v + ['nic' => $r->nic, 'address' => $r->address, 'active' => true]);

        return back()->with('success', 'Technician added.');
    }

    public function show(Technician $technician)
    {
        return view('technicians.show', ['technician' => $technician->load(['jobs.intake.bike', 'attendance' => fn ($q) => $q->latest('date')->limit(31)])]);
    }

    public function attendance(Request $r, Technician $technician)
    {
        $v = $r->validate(['date' => 'required|date', 'status' => 'required|in:Present,Absent,Half Day,Leave,Late,Holiday', 'in_time' => 'nullable', 'out_time' => 'nullable', 'working_hours' => 'nullable|numeric|min:0', 'ot_hours' => 'nullable|numeric|min:0', 'late_minutes' => 'nullable|integer|min:0']);
        TechnicianAttendance::updateOrCreate(['technician_id' => $technician->id, 'date' => $v['date']], $v + ['note' => $r->note, 'created_by' => auth()->id()]);

        return back()->with('success', 'Attendance saved.');
    }
}
