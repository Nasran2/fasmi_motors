<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\BikeIntake;
use App\Services\AccountService;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\JobCard;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ServiceReminder;
use App\Models\Technician;
use Illuminate\Contracts\View\View;

class DashboardController extends Controller
{
    public function __invoke(): View
    {
        if (session('shop_mode', 'workshop') === 'helmet') {
            return $this->helmetDashboard();
        }

        $today = today();
        $yesterday = $today->copy()->subDay();
        $finalStatuses = ['Completed', 'Billed', 'Delivered', 'Cancelled'];
        $completedStatuses = ['Completed', 'Billed', 'Delivered'];

        $todayIntakes = BikeIntake::whereDate('created_at', $today)->count();
        $yesterdayIntakes = BikeIntake::whereDate('created_at', $yesterday)->count();
        $activeJobs = JobCard::whereNotIn('status', $finalStatuses)->count();
        $completedToday = JobCard::whereIn('status', $completedStatuses)->whereDate('updated_at', $today)->count();
        $completedYesterday = JobCard::whereIn('status', $completedStatuses)->whereDate('updated_at', $yesterday)->count();
        $todaySales = (float) Invoice::whereDate('created_at', $today)->sum('total');
        $yesterdaySales = (float) Invoice::whereDate('created_at', $yesterday)->sum('total');
        $partsSales = (float) JobCard::query()->join('invoices', 'invoices.job_card_id', '=', 'job_cards.id')->whereDate('invoices.created_at', $today)->sum('job_cards.parts_total');
        $yesterdayPartsSales = (float) JobCard::query()->join('invoices', 'invoices.job_card_id', '=', 'job_cards.id')->whereDate('invoices.created_at', $yesterday)->sum('job_cards.parts_total');

        $accountService = app(AccountService::class);
        $mainBalance = $accountService->getRunningBalance('main');
        $bankBalance = $accountService->getRunningBalance('bank');
        $partsBalance = $accountService->getRunningBalance('spare_parts');
        
        $pendingDue = (float) Invoice::where('due_amount', '>', 0)->sum('due_amount');

        $cards = [
            ['label' => 'Today Bike Intake', 'value' => $todayIntakes, 'format' => 'number', 'trend' => $this->trend($todayIntakes, $yesterdayIntakes), 'note' => 'vs yesterday', 'icon' => 'bike', 'tone' => 'red'],
            ['label' => 'Active Job Cards', 'value' => $activeJobs, 'format' => 'number', 'trend' => null, 'note' => 'currently in workshop', 'icon' => 'clipboard', 'tone' => 'orange'],
            ['label' => 'Completed Repairs', 'value' => $completedToday, 'format' => 'number', 'trend' => $this->trend($completedToday, $completedYesterday), 'note' => 'vs yesterday', 'icon' => 'check', 'tone' => 'green'],
            ['label' => 'Today Sales', 'value' => $todaySales, 'format' => 'money', 'trend' => $this->trend($todaySales, $yesterdaySales), 'note' => 'vs yesterday', 'icon' => 'money', 'tone' => 'blue'],
            ['label' => 'Spare Parts Sales', 'value' => $partsSales, 'format' => 'money', 'trend' => $this->trend($partsSales, $yesterdayPartsSales), 'note' => 'vs yesterday', 'icon' => 'parts', 'tone' => 'violet'],
            ['label' => 'Main Account Balance', 'value' => $mainBalance, 'format' => 'money', 'trend' => null, 'note' => 'live ledger balance', 'icon' => 'wallet', 'tone' => 'teal'],
            ['label' => 'Bank Balance', 'value' => $bankBalance, 'format' => 'money', 'trend' => null, 'note' => 'live ledger balance', 'icon' => 'bank', 'tone' => 'navy'],
            ['label' => 'Pending Due Amount', 'value' => $pendingDue, 'format' => 'money', 'trend' => null, 'note' => Invoice::where('due_amount', '>', 0)->count().' unpaid invoices', 'icon' => 'warning', 'tone' => 'rose'],
        ];

        $weekStart = now()->startOfWeek();
        $salesSeries = collect(range(0, 6))->map(function (int $offset) use ($weekStart): array {
            $date = $weekStart->addDays($offset);

            return ['label' => $date->format('D'), 'date' => $date->format('d M'), 'value' => (float) Invoice::whereDate('created_at', $date)->sum('total')];
        });
        $salesMaximum = max(1, (float) $salesSeries->max('value'));
        $salesPoints = $salesSeries->values()->map(fn (array $item, int $index): string => ($index * 100).','.round(155 - (($item['value'] / $salesMaximum) * 125), 2))->implode(' ');
        $weekSales = (float) $salesSeries->sum('value');
        $lastWeekSales = (float) Invoice::whereBetween('created_at', [$weekStart->subWeek(), $weekStart->subSecond()])->sum('total');

        $statusCounts = JobCard::selectRaw('status, COUNT(*) total')->groupBy('status')->pluck('total', 'status');
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
            ->leftJoin('job_cards', function ($join) use ($weekStart): void {
                $join->on('job_cards.main_technician_id', '=', 'technicians.id')->where('job_cards.created_at', '>=', $weekStart->startOfMonth());
            })
            ->leftJoin('invoices', 'invoices.job_card_id', '=', 'job_cards.id')
            ->where('technicians.active', true)->whereNull('technicians.deleted_at')
            ->groupBy('technicians.id', 'technicians.name')->orderByDesc('performance_total')->limit(5)->get();
        $performanceMaximum = max(1, (float) $technicianPerformance->max('performance_total'));

        $lowStock = Product::withSum('stocks', 'quantity')->where('active', true)->get()
            ->filter(fn (Product $product): bool => (float) ($product->stocks_sum_quantity ?? 0) <= (float) $product->minimum_stock)
            ->sortBy('stocks_sum_quantity')->take(5);

        $attendance = Technician::where('active', true)->with(['attendance' => fn ($query) => $query->whereDate('date', $today)])->orderBy('name')->limit(6)->get();
        $collectionToday = (float) Payment::whereDate('paid_at', $today)->sum('amount');
        $expensesToday = (float) Expense::whereDate('expense_date', $today)->sum('amount');

        return view('dashboard', [
            'cards' => $cards,
            'salesSeries' => $salesSeries,
            'salesPoints' => $salesPoints,
            'salesMaximum' => $salesMaximum,
            'weekSales' => $weekSales,
            'averageDailySales' => $weekSales / max(1, min(7, now()->dayOfWeekIso)),
            'weekTrend' => $this->trend($weekSales, $lastWeekSales),
            'jobSegments' => $jobSegments,
            'jobTotal' => (int) $jobSegments->sum('count'),
            'jobGradient' => $jobGradient,
            'technicianPerformance' => $technicianPerformance,
            'performanceMaximum' => $performanceMaximum,
            'recentIntakes' => BikeIntake::with('bike.customer', 'jobCard.technician')->latest()->limit(5)->get(),
            'lowStock' => $lowStock,
            'reminders' => ServiceReminder::with('bike.customer')->where('status', 'Pending')->whereNotNull('due_date')->orderBy('due_date')->limit(4)->get(),
            'attendance' => $attendance,
            'recentTransactions' => AccountTransaction::with('account')->latest('transaction_date')->limit(5)->get(),
            'collectionToday' => $collectionToday,
            'expensesToday' => $expensesToday,
            'netToday' => $collectionToday - $expensesToday,
            'partsBalance' => $partsBalance,
        ]);
    }

    private function trend(float|int $current, float|int $previous): float
    {
        if ((float) $previous === 0.0) {
            return (float) $current > 0 ? 100 : 0;
        }

        return round((((float) $current - (float) $previous) / abs((float) $previous)) * 100, 1);
    }

    private function helmetDashboard(): View
    {
        $today = today();
        $yesterday = $today->copy()->subDay();

        $todaySales = (float) Invoice::whereDate('created_at', $today)->sum('total');
        $yesterdaySales = (float) Invoice::whereDate('created_at', $yesterday)->sum('total');

        $pendingDue = (float) Invoice::where('due_amount', '>', 0)->sum('due_amount');
        $lowStockCount = Product::withSum('stocks', 'quantity')->where('active', true)->get()
            ->filter(fn (Product $p) => (float) ($p->stocks_sum_quantity ?? 0) <= (float) $p->minimum_stock)->count();

        $cards = [
            ['label' => 'Today Sales', 'value' => $todaySales, 'format' => 'money', 'trend' => $this->trend($todaySales, $yesterdaySales), 'note' => 'vs yesterday', 'icon' => 'money', 'tone' => 'blue'],
            ['label' => 'Pending Due Amount', 'value' => $pendingDue, 'format' => 'money', 'trend' => null, 'note' => Invoice::where('due_amount', '>', 0)->count().' unpaid invoices', 'icon' => 'warning', 'tone' => 'rose'],
            ['label' => 'Helmet Products', 'value' => Product::count(), 'format' => 'number', 'trend' => null, 'note' => 'active products in catalog', 'icon' => 'parts', 'tone' => 'violet'],
            ['label' => 'Low Stock Items', 'value' => $lowStockCount, 'format' => 'number', 'trend' => null, 'note' => 'require attention', 'icon' => 'warning', 'tone' => 'orange'],
        ];

        $weekStart = now()->startOfWeek();
        $salesSeries = collect(range(0, 6))->map(function (int $offset) use ($weekStart): array {
            $date = $weekStart->addDays($offset);
            return ['label' => $date->format('D'), 'date' => $date->format('d M'), 'value' => (float) Invoice::whereDate('created_at', $date)->sum('total')];
        });
        $salesMaximum = max(1, (float) $salesSeries->max('value'));
        $salesPoints = $salesSeries->values()->map(fn (array $item, int $index): string => ($index * 100).','.round(155 - (($item['value'] / $salesMaximum) * 125), 2))->implode(' ');
        $weekSales = (float) $salesSeries->sum('value');
        $lastWeekSales = (float) Invoice::whereBetween('created_at', [$weekStart->subWeek(), $weekStart->subSecond()])->sum('total');

        $lowStock = Product::withSum('stocks', 'quantity')->where('active', true)->get()
            ->filter(fn (Product $product): bool => (float) ($product->stocks_sum_quantity ?? 0) <= (float) $product->minimum_stock)
            ->sortBy('stocks_sum_quantity')->take(5);

        $recentSales = Invoice::with('customer')->latest()->limit(5)->get();

        return view('dashboard-helmet', [
            'cards' => $cards,
            'salesSeries' => $salesSeries,
            'salesPoints' => $salesPoints,
            'salesMaximum' => $salesMaximum,
            'weekSales' => $weekSales,
            'averageDailySales' => $weekSales / max(1, min(7, now()->dayOfWeekIso)),
            'weekTrend' => $this->trend($weekSales, $lastWeekSales),
            'lowStock' => $lowStock,
            'recentSales' => $recentSales,
        ]);
    }
}
