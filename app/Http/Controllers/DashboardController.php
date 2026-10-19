<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\BikeIntake;
use App\Models\Cheque;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\JobCard;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ServiceReminder;
use App\Models\Technician;
use App\Services\AccountService;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        if (session('shop_mode', 'workshop') === 'helmet') {
            return $this->helmetDashboard($request);
        }

        [$startDate, $endDate, $previousStartDate, $previousEndDate, $filterLabel, $periodName] = $this->getFilterDates($request);
        $today = today();
        $finalStatuses = ['Completed', 'Billed', 'Delivered', 'Cancelled'];
        $completedStatuses = ['Completed', 'Billed', 'Delivered'];

        $todayIntakes = BikeIntake::whereBetween('intake_date', [$startDate->toDateString(), $endDate->toDateString()])->count();
        $yesterdayIntakes = BikeIntake::whereBetween('intake_date', [$previousStartDate->toDateString(), $previousEndDate->toDateString()])->count();
        $activeJobs = JobCard::whereNotIn('status', $finalStatuses)->count();
        $completedToday = JobCard::whereIn('status', $completedStatuses)->whereBetween('updated_at', [$startDate, $endDate])->count();
        $completedYesterday = JobCard::whereIn('status', $completedStatuses)->whereBetween('updated_at', [$previousStartDate, $previousEndDate])->count();
        $todaySales = (float) Invoice::where('status', '!=', 'Cancelled')->whereBetween('created_at', [$startDate, $endDate])->sum('total');
        $yesterdaySales = (float) Invoice::where('status', '!=', 'Cancelled')->whereBetween('created_at', [$previousStartDate, $previousEndDate])->sum('total');
        $partsSales = (float) Invoice::where('status', '!=', 'Cancelled')->where('status', '!=', 'Cancelled')->whereBetween('created_at', [$startDate, $endDate])->sum('parts_amount');
        $yesterdayPartsSales = (float) Invoice::where('status', '!=', 'Cancelled')->where('status', '!=', 'Cancelled')->whereBetween('created_at', [$previousStartDate, $previousEndDate])->sum('parts_amount');

        $accountService = app(AccountService::class);
        $mainBalance = $accountService->getRunningBalance('main');
        $bankBalance = $accountService->getRunningBalance('bank');
        $partsBalance = $accountService->getRunningBalance('spare_parts');

        $pendingDue = (float) Invoice::where('status', '!=', 'Cancelled')->where('due_amount', '>', 0)->sum('due_amount');

        $cards = [
            ['label' => 'Bike Intake', 'value' => $todayIntakes, 'format' => 'number', 'trend' => $this->trend($todayIntakes, $yesterdayIntakes), 'note' => $filterLabel, 'icon' => 'bike', 'tone' => 'red'],
            ['label' => 'Active Job Cards', 'value' => $activeJobs, 'format' => 'number', 'trend' => null, 'note' => 'currently in workshop', 'icon' => 'clipboard', 'tone' => 'orange'],
            ['label' => 'Completed Repairs', 'value' => $completedToday, 'format' => 'number', 'trend' => $this->trend($completedToday, $completedYesterday), 'note' => $filterLabel, 'icon' => 'check', 'tone' => 'green'],
            ['label' => 'Sales', 'value' => $todaySales, 'format' => 'money', 'trend' => $this->trend($todaySales, $yesterdaySales), 'note' => $filterLabel, 'icon' => 'money', 'tone' => 'blue'],
            ['label' => 'Spare Parts Sales', 'value' => $partsSales, 'format' => 'money', 'trend' => $this->trend($partsSales, $yesterdayPartsSales), 'note' => $filterLabel, 'icon' => 'parts', 'tone' => 'violet'],
            ['label' => 'Main Account Balance', 'value' => $mainBalance, 'format' => 'money', 'trend' => null, 'note' => 'live ledger balance', 'icon' => 'wallet', 'tone' => 'teal'],
            ['label' => 'Spare Parts / Owner Balance', 'value' => $partsBalance, 'format' => 'money', 'trend' => null, 'note' => 'live ledger balance', 'icon' => 'wallet', 'tone' => 'green'],
            ['label' => 'Bank Balance', 'value' => $bankBalance, 'format' => 'money', 'trend' => null, 'note' => 'live ledger balance', 'icon' => 'bank', 'tone' => 'navy'],
            ['label' => 'Pending Due Amount', 'value' => $pendingDue, 'format' => 'money', 'trend' => null, 'note' => Invoice::where('status', '!=', 'Cancelled')->where('due_amount', '>', 0)->count().' unpaid invoices', 'icon' => 'warning', 'tone' => 'rose'],
        ];

        $daysDiff = $startDate->diffInDays($endDate);
        if ($daysDiff <= 1) {
            $salesSeries = collect(range(0, 23))->map(function (int $hour) use ($startDate): array {
                $startHour = $startDate->copy()->startOfDay()->addHours($hour);
                $endHour = $startHour->copy()->endOfHour();

                return ['label' => $startHour->format('H:00'), 'date' => $startHour->format('H:00'), 'value' => (float) Invoice::where('status', '!=', 'Cancelled')->whereBetween('created_at', [$startHour, $endHour])->sum('total')];
            });
        } elseif ($daysDiff <= 31) {
            $salesSeries = collect(range(0, (int) $daysDiff))->map(function (int $offset) use ($startDate): array {
                $date = $startDate->copy()->addDays($offset);

                return ['label' => $date->format('d M'), 'date' => $date->format('d M'), 'value' => (float) Invoice::where('status', '!=', 'Cancelled')->whereDate('created_at', $date)->sum('total')];
            });
        } else {
            $monthsDiff = $startDate->diffInMonths($endDate);
            $salesSeries = collect(range(0, (int) $monthsDiff))->map(function (int $offset) use ($startDate, $endDate): array {
                $monthStart = $startDate->copy()->addMonths($offset)->startOfMonth();
                $monthEnd = $monthStart->copy()->endOfMonth();
                if ($monthEnd->gt($endDate)) {
                    $monthEnd = $endDate->copy();
                }

                return ['label' => $monthStart->format('M y'), 'date' => $monthStart->format('M Y'), 'value' => (float) Invoice::where('status', '!=', 'Cancelled')->whereBetween('created_at', [$monthStart, $monthEnd])->sum('total')];
            });
        }

        $salesMaximum = max(1, (float) $salesSeries->max('value'));
        $salesPoints = $salesSeries->values()->map(function (array $item, int $index) use ($salesMaximum, $salesSeries): string {
            $x = $salesSeries->count() > 1 ? ($index / ($salesSeries->count() - 1)) * 600 : 300;

            return $x.','.round(155 - (($item['value'] / $salesMaximum) * 125), 2);
        })->implode(' ');

        $periodSales = $todaySales;
        $previousPeriodSales = $yesterdaySales;
        $averageDailySales = $daysDiff > 0 ? $periodSales / ($startDate->copy()->startOfDay()->diffInDays($endDate->copy()->startOfDay()) + 1) : $periodSales;

        $statusCounts = JobCard::whereBetween('created_at', [$startDate, $endDate])->selectRaw('status, COUNT(*) total')->groupBy('status')->pluck('total', 'status');
        $jobSegments = collect([
            ['label' => 'Pending', 'count' => (int) collect(['Received', 'Assigned', 'On Hold'])->sum(fn (string $status) => $statusCounts->get($status, 0)), 'color' => '#ef172d'],
            ['label' => 'In Progress', 'count' => (int) $statusCounts->get('In Progress', 0), 'color' => '#f7902f'],
            ['label' => 'Waiting for Parts', 'count' => (int) $statusCounts->get('Waiting for Parts', 0), 'color' => '#3985ed'],
            ['label' => 'Completed', 'count' => (int) collect($completedStatuses)->sum(fn (string $status) => $statusCounts->get($status, 0)), 'color' => '#2dbd5b'],
        ]);
        $actualJobTotal = (int) $jobSegments->sum('count');
        $jobTotal = max(1, $actualJobTotal);
        $cursor = 0;
        $jobGradient = $actualJobTotal === 0
            ? '#e5e7eb 0% 100%'
            : $jobSegments->map(function (array $segment) use (&$cursor, $jobTotal): string {
                $start = $cursor;
                $cursor += ($segment['count'] / $jobTotal) * 100;

                return "{$segment['color']} {$start}% {$cursor}%";
            })->implode(', ');

        $technicianPerformance = Technician::query()
            ->select('technicians.id', 'technicians.name')
            ->selectRaw('COALESCE(SUM(invoices.total), 0) performance_total')
            ->leftJoin('job_cards', function ($join) use ($startDate, $endDate): void {
                $join->on('job_cards.main_technician_id', '=', 'technicians.id')->whereBetween('job_cards.created_at', [$startDate, $endDate]);
            })
            ->leftJoin('invoices', 'invoices.job_card_id', '=', 'job_cards.id')
            ->where('technicians.active', true)->whereNull('technicians.deleted_at')
            ->groupBy('technicians.id', 'technicians.name')->orderByDesc('performance_total')->limit(5)->get();
        $performanceMaximum = max(1, (float) $technicianPerformance->max('performance_total'));

        $lowStock = Product::withSum('stocks', 'quantity')->where('active', true)
            ->whereRaw('(select coalesce(sum(quantity), 0) from store_stocks where store_stocks.product_id = products.id) <= products.minimum_stock')
            ->orderBy('stocks_sum_quantity')->limit(5)->get();

        $attendance = Technician::where('active', true)->with(['attendance' => fn ($query) => $query->whereDate('date', $today)])->orderBy('name')->limit(6)->get();
        $collectionToday = (float) Payment::where('status', 'Completed')->whereBetween('paid_at', [$startDate, $endDate])->sum('amount');
        $expensesToday = (float) Expense::whereBetween('expense_date', [$startDate, $endDate])->sum('amount');

        return view('dashboard', [
            'chequeAlerts' => Cheque::with('forwardedSupplier')->whereIn('status', ['Pending', 'Deposited'])->whereDate('cheque_date', '<=', today()->addDays(2))->orderBy('cheque_date')->get(),
            'cards' => $cards,
            'salesSeries' => $salesSeries,
            'salesPoints' => $salesPoints,
            'salesMaximum' => $salesMaximum,
            'weekSales' => $periodSales,
            'averageDailySales' => $averageDailySales,
            'weekTrend' => $this->trend($periodSales, $previousPeriodSales),
            'jobSegments' => $jobSegments,
            'jobTotal' => (int) $jobSegments->sum('count'),
            'jobGradient' => $jobGradient,
            'technicianPerformance' => $technicianPerformance,
            'performanceMaximum' => $performanceMaximum,
            'recentIntakes' => BikeIntake::with('bike.customer', 'jobCard.technician')->latest('intake_date')->latest()->limit(7)->get(),
            'lowStock' => $lowStock,
            'reminders' => ServiceReminder::with('bike.customer')->where('status', 'Pending')->whereNotNull('due_date')->orderBy('due_date')->limit(4)->get(),
            'attendance' => $attendance,
            'recentTransactions' => AccountTransaction::with('account')->whereIn('account_id', Account::pluck('id'))->whereBetween('transaction_date', [$startDate, $endDate])->latest('transaction_date')->limit(5)->get(),
            'collectionToday' => $collectionToday,
            'expensesToday' => $expensesToday,
            'netToday' => $collectionToday - $expensesToday,
            'partsBalance' => $partsBalance,
            'periodName' => $periodName,
        ]);
    }

    private function trend(float|int $current, float|int $previous): float
    {
        if ((float) $previous === 0.0) {
            return (float) $current > 0 ? 100 : 0;
        }

        return round((((float) $current - (float) $previous) / abs((float) $previous)) * 100, 1);
    }

    private function helmetDashboard(Request $request): View
    {
        [$startDate, $endDate, $previousStartDate, $previousEndDate, $filterLabel, $periodName] = $this->getFilterDates($request);

        $todaySales = (float) Invoice::where('status', '!=', 'Cancelled')->whereBetween('created_at', [$startDate, $endDate])->sum('total');
        $yesterdaySales = (float) Invoice::where('status', '!=', 'Cancelled')->whereBetween('created_at', [$previousStartDate, $previousEndDate])->sum('total');

        $pendingDue = (float) Invoice::where('status', '!=', 'Cancelled')->where('due_amount', '>', 0)->sum('due_amount');
        $lowStockCount = Product::where('active', true)
            ->whereRaw('(select coalesce(sum(quantity), 0) from store_stocks where store_stocks.product_id = products.id) <= products.minimum_stock')
            ->count();

        $cards = [
            ['label' => 'Sales', 'value' => $todaySales, 'format' => 'money', 'trend' => $this->trend($todaySales, $yesterdaySales), 'note' => $filterLabel, 'icon' => 'money', 'tone' => 'blue'],
            ['label' => 'Pending Due Amount', 'value' => $pendingDue, 'format' => 'money', 'trend' => null, 'note' => Invoice::where('status', '!=', 'Cancelled')->where('due_amount', '>', 0)->count().' unpaid invoices', 'icon' => 'warning', 'tone' => 'rose'],
            ['label' => 'Helmet Products', 'value' => Product::count(), 'format' => 'number', 'trend' => null, 'note' => 'active products in catalog', 'icon' => 'parts', 'tone' => 'violet'],
            ['label' => 'Low Stock Items', 'value' => $lowStockCount, 'format' => 'number', 'trend' => null, 'note' => 'require attention', 'icon' => 'warning', 'tone' => 'orange'],
        ];

        $daysDiff = $startDate->diffInDays($endDate);
        if ($daysDiff <= 1) {
            $salesSeries = collect(range(0, 23))->map(function (int $hour) use ($startDate): array {
                $startHour = $startDate->copy()->startOfDay()->addHours($hour);
                $endHour = $startHour->copy()->endOfHour();

                return ['label' => $startHour->format('H:00'), 'date' => $startHour->format('H:00'), 'value' => (float) Invoice::where('status', '!=', 'Cancelled')->whereBetween('created_at', [$startHour, $endHour])->sum('total')];
            });
        } elseif ($daysDiff <= 31) {
            $salesSeries = collect(range(0, (int) $daysDiff))->map(function (int $offset) use ($startDate): array {
                $date = $startDate->copy()->addDays($offset);

                return ['label' => $date->format('d M'), 'date' => $date->format('d M'), 'value' => (float) Invoice::where('status', '!=', 'Cancelled')->whereDate('created_at', $date)->sum('total')];
            });
        } else {
            $monthsDiff = $startDate->diffInMonths($endDate);
            $salesSeries = collect(range(0, (int) $monthsDiff))->map(function (int $offset) use ($startDate, $endDate): array {
                $monthStart = $startDate->copy()->addMonths($offset)->startOfMonth();
                $monthEnd = $monthStart->copy()->endOfMonth();
                if ($monthEnd->gt($endDate)) {
                    $monthEnd = $endDate->copy();
                }

                return ['label' => $monthStart->format('M y'), 'date' => $monthStart->format('M Y'), 'value' => (float) Invoice::where('status', '!=', 'Cancelled')->whereBetween('created_at', [$monthStart, $monthEnd])->sum('total')];
            });
        }

        $salesMaximum = max(1, (float) $salesSeries->max('value'));
        $salesPoints = $salesSeries->values()->map(function (array $item, int $index) use ($salesMaximum, $salesSeries): string {
            $x = $salesSeries->count() > 1 ? ($index / ($salesSeries->count() - 1)) * 600 : 300;

            return $x.','.round(155 - (($item['value'] / $salesMaximum) * 125), 2);
        })->implode(' ');

        $periodSales = $todaySales;
        $previousPeriodSales = $yesterdaySales;
        $averageDailySales = $daysDiff > 0 ? $periodSales / ($startDate->copy()->startOfDay()->diffInDays($endDate->copy()->startOfDay()) + 1) : $periodSales;

        $lowStock = Product::withSum('stocks', 'quantity')->where('active', true)
            ->whereRaw('(select coalesce(sum(quantity), 0) from store_stocks where store_stocks.product_id = products.id) <= products.minimum_stock')
            ->orderBy('stocks_sum_quantity')->limit(5)->get();

        $recentSales = Invoice::where('status', '!=', 'Cancelled')->with('customer')->latest()->limit(7)->get();

        return view('dashboard-helmet', [
            'chequeAlerts' => Cheque::with('forwardedSupplier')->whereIn('status', ['Pending', 'Deposited'])->whereDate('cheque_date', '<=', today()->addDays(2))->orderBy('cheque_date')->get(),
            'cards' => $cards,
            'salesSeries' => $salesSeries,
            'salesPoints' => $salesPoints,
            'salesMaximum' => $salesMaximum,
            'weekSales' => $periodSales,
            'averageDailySales' => $averageDailySales,
            'weekTrend' => $this->trend($periodSales, $previousPeriodSales),
            'lowStock' => $lowStock,
            'recentSales' => $recentSales,
            'periodName' => $periodName,
        ]);
    }

    private function getFilterDates(Request $request): array
    {
        $request->validate(['filter' => 'nullable|in:today,yesterday,this_week,last_week,this_month,last_month,this_year,last_year,custom', 'start_date' => 'nullable|required_if:filter,custom|date_format:Y-m-d', 'end_date' => 'nullable|required_if:filter,custom|date_format:Y-m-d|after_or_equal:start_date']);
        $filter = $request->input('filter', 'today');
        $now = today();
        $periodName = 'Today';

        switch ($filter) {
            case 'this_week':
            case 'last_week':
                $startDate = $now->copy()->startOfWeek()->subWeeks($filter === 'last_week' ? 1 : 0);
                $endDate = $startDate->copy()->endOfWeek();
                $previousStartDate = $startDate->copy()->subWeek();
                $previousEndDate = $endDate->copy()->subWeek();
                $filterLabel = 'vs previous week';
                $periodName = $filter === 'last_week' ? 'Last Week' : 'This Week';
                break;
            case 'yesterday':
                $startDate = $now->copy()->subDay()->startOfDay();
                $endDate = $now->copy()->subDay()->endOfDay();
                $previousStartDate = $now->copy()->subDays(2)->startOfDay();
                $previousEndDate = $now->copy()->subDays(2)->endOfDay();
                $filterLabel = 'vs day before yesterday';
                $periodName = 'Yesterday';
                break;
            case 'this_month':
                $startDate = $now->copy()->startOfMonth();
                $endDate = $now->copy()->endOfMonth();
                $previousStartDate = $now->copy()->subMonth()->startOfMonth();
                $previousEndDate = $now->copy()->subMonth()->endOfMonth();
                $filterLabel = 'vs last month';
                $periodName = 'This Month';
                break;
            case 'last_month':
                $startDate = $now->copy()->subMonth()->startOfMonth();
                $endDate = $now->copy()->subMonth()->endOfMonth();
                $previousStartDate = $now->copy()->subMonths(2)->startOfMonth();
                $previousEndDate = $now->copy()->subMonths(2)->endOfMonth();
                $filterLabel = 'vs previous month';
                $periodName = 'Last Month';
                break;
            case 'this_year':
                $startDate = $now->copy()->startOfYear();
                $endDate = $now->copy()->endOfYear();
                $previousStartDate = $now->copy()->subYear()->startOfYear();
                $previousEndDate = $now->copy()->subYear()->endOfYear();
                $filterLabel = 'vs last year';
                $periodName = 'This Year';
                break;
            case 'last_year':
                $startDate = $now->copy()->subYear()->startOfYear();
                $endDate = $now->copy()->subYear()->endOfYear();
                $previousStartDate = $now->copy()->subYears(2)->startOfYear();
                $previousEndDate = $now->copy()->subYears(2)->endOfYear();
                $filterLabel = 'vs previous year';
                $periodName = 'Last Year';
                break;
            case 'custom':
                $startDate = $request->input('start_date') ? Carbon::parse($request->input('start_date'))->startOfDay() : $now->copy()->startOfDay();
                $endDate = $request->input('end_date') ? Carbon::parse($request->input('end_date'))->endOfDay() : $now->copy()->endOfDay();
                $days = $startDate->diffInDays($endDate);
                $previousStartDate = $startDate->copy()->subDays($days + 1)->startOfDay();
                $previousEndDate = $startDate->copy()->subDay()->endOfDay();
                $filterLabel = 'vs previous period';
                $periodName = $startDate->format('M d').' - '.$endDate->format('M d');
                break;
            case 'today':
            default:
                $filter = 'today';
                $startDate = $now->copy()->startOfDay();
                $endDate = $now->copy()->endOfDay();
                $previousStartDate = $now->copy()->subDay()->startOfDay();
                $previousEndDate = $now->copy()->subDay()->endOfDay();
                $filterLabel = 'vs yesterday';
                $periodName = 'Today';
                break;
        }

        return [$startDate, $endDate, $previousStartDate, $previousEndDate, $filterLabel, $periodName];
    }
}
