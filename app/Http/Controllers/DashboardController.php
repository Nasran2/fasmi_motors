<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\BikeIntake;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\JobCard;
use App\Models\Product;
use App\Models\TechnicianAttendance;

class DashboardController extends Controller
{
    public function __invoke()
    {
        $cards = ['Today bike intakes' => BikeIntake::whereDate('created_at', today())->count(), 'Completed today' => JobCard::where('status', 'Completed')->whereDate('updated_at', today())->count(),
            'Pending jobs' => JobCard::whereNotIn('status', ['Completed', 'Billed', 'Delivered', 'Cancelled'])->count(), 'Waiting for parts' => JobCard::where('status', 'Waiting for Parts')->count(),
            'Today sales' => Invoice::whereDate('created_at', today())->sum('total'), 'Today expenses' => Expense::whereDate('expense_date', today())->sum('amount'),
            'Attendance today' => TechnicianAttendance::whereDate('date', today())->whereIn('status', ['Present', 'Late'])->count(), 'Low stock items' => Product::withSum('stocks', 'quantity')->get()->filter(fn ($p) => (float) $p->stocks_sum_quantity <= (float) $p->minimum_stock)->count()];

        return view('dashboard', ['cards' => $cards, 'accounts' => Account::all(), 'recentJobs' => JobCard::with('intake.bike.customer', 'technician')->latest()->limit(6)->get()]);
    }
}
