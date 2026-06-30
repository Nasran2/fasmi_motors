<?php

namespace App\Http\Controllers;

use App\Models\BikeIntake;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\JobCard;
use App\Models\Payment;
use App\Models\Technician;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WorkspacePageController extends Controller
{
    public function __invoke(Request $request, string $section, string $page): View
    {
        $group = collect(config('workshop-navigation.groups'))->firstWhere('slug', $section);
        abort_unless($group, 404);

        $child = collect($group['children'])->firstWhere('slug', $page);
        abort_unless($child, 404);

        if ($section === 'dashboard' && $page === 'today-summary') {
            return $this->todaySummary($group, $child);
        }

        if ($section === 'dashboard' && $page === 'workshop-performance') {
            return $this->workshopPerformance($group, $child);
        }

        if ($section === 'bike-intake' && in_array($page, ['pending-intakes', 'service-jobs', 'technician-bikes', 'due-bike-records'], true)) {
            return $this->intakeRecords($request, $group, $child, $page);
        }

        $jobStatuses = [
            'assigned-jobs' => 'Assigned',
            'in-progress-jobs' => 'In Progress',
            'waiting-for-parts' => 'Waiting for Parts',
            'completed-jobs' => 'Completed',
            'delivered-jobs' => 'Delivered',
            'cancelled-jobs' => 'Cancelled',
        ];

        if ($section === 'job-cards' && isset($jobStatuses[$page])) {
            return $this->jobRecords($request, $group, $child, $jobStatuses[$page]);
        }

        return view('workspace.page', ['group' => $group, 'page' => $child]);
    }

    private function todaySummary(array $group, array $page): View
    {
        $start = today()->startOfDay();
        $end = today()->endOfDay();
        $closedStatuses = ['Completed', 'Billed', 'Delivered', 'Cancelled'];

        $cards = [
            ['label' => 'Bike Intakes', 'value' => BikeIntake::whereBetween('created_at', [$start, $end])->count(), 'icon' => 'bike', 'tone' => 'red'],
            ['label' => 'Jobs Created', 'value' => JobCard::whereBetween('created_at', [$start, $end])->count(), 'icon' => 'clipboard', 'tone' => 'orange'],
            ['label' => 'Active Jobs', 'value' => JobCard::whereNotIn('status', $closedStatuses)->count(), 'icon' => 'clipboard', 'tone' => 'blue'],
            ['label' => 'Completed Today', 'value' => JobCard::whereIn('status', ['Completed', 'Billed', 'Delivered'])->whereBetween('updated_at', [$start, $end])->count(), 'icon' => 'check', 'tone' => 'green'],
            ['label' => 'Sales Today', 'value' => (float) Invoice::whereBetween('created_at', [$start, $end])->sum('total'), 'icon' => 'money', 'tone' => 'violet', 'money' => true],
            ['label' => 'Collected Today', 'value' => (float) Payment::whereBetween('paid_at', [$start, $end])->sum('amount'), 'icon' => 'wallet', 'tone' => 'teal', 'money' => true],
            ['label' => 'Expenses Today', 'value' => (float) Expense::whereBetween('expense_date', [$start->toDateString(), $end->toDateString()])->sum('amount'), 'icon' => 'warning', 'tone' => 'rose', 'money' => true],
            ['label' => 'Outstanding Due', 'value' => (float) Invoice::sum('due_amount'), 'icon' => 'bank', 'tone' => 'navy', 'money' => true],
        ];

        return view('workspace.today-summary', [
            'group' => $group,
            'page' => $page,
            'cards' => $cards,
            'recentIntakes' => BikeIntake::with('bike.customer', 'jobCard.technician')->whereBetween('created_at', [$start, $end])->latest()->limit(8)->get(),
            'recentJobs' => JobCard::with('intake.bike.customer', 'technician')->whereBetween('updated_at', [$start, $end])->latest('updated_at')->limit(8)->get(),
            'jobsByStatus' => JobCard::selectRaw('status, count(*) total')->groupBy('status')->orderByDesc('total')->pluck('total', 'status'),
            'netCash' => (float) Payment::whereBetween('paid_at', [$start, $end])->sum('amount') - (float) Expense::whereBetween('expense_date', [$start->toDateString(), $end->toDateString()])->sum('amount'),
        ]);
    }

    private function workshopPerformance(array $group, array $page): View
    {
        $start = now()->startOfMonth();
        $end = now()->endOfMonth();
        $jobs = JobCard::whereBetween('created_at', [$start, $end]);
        $totalJobs = (clone $jobs)->count();
        $completedJobs = (clone $jobs)->whereIn('status', ['Completed', 'Billed', 'Delivered'])->count();
        $revenue = (float) Invoice::whereBetween('created_at', [$start, $end])->sum('total');
        $turnaroundJobs = (clone $jobs)->whereIn('status', ['Completed', 'Billed', 'Delivered'])->get(['created_at', 'updated_at']);
        $averageHours = $turnaroundJobs->isEmpty() ? 0 : round($turnaroundJobs->avg(fn (JobCard $job) => $job->created_at->diffInMinutes($job->updated_at) / 60), 1);

        $performanceRows = JobCard::query()
            ->whereBetween('job_cards.created_at', [$start, $end])
            ->whereNotNull('main_technician_id')
            ->selectRaw('main_technician_id, count(*) jobs_count, sum(parts_total + service_total + labor_total + washing_total + other_total - discount + tax) billed_total')
            ->groupBy('main_technician_id')
            ->orderByDesc('billed_total')
            ->get();
        $technicianNames = Technician::whereIn('id', $performanceRows->pluck('main_technician_id'))->pluck('name', 'id');

        return view('workspace.workshop-performance', [
            'group' => $group,
            'page' => $page,
            'periodLabel' => $start->format('F Y'),
            'metrics' => [
                ['label' => 'Jobs This Month', 'value' => number_format($totalJobs), 'detail' => 'Created during '.$start->format('F'), 'tone' => 'blue'],
                ['label' => 'Completion Rate', 'value' => ($totalJobs ? round($completedJobs / $totalJobs * 100, 1) : 0).'%', 'detail' => $completedJobs.' completed jobs', 'tone' => 'green'],
                ['label' => 'Workshop Revenue', 'value' => 'Rs. '.number_format($revenue, 2), 'detail' => 'Invoiced this month', 'tone' => 'red'],
                ['label' => 'Average Job Value', 'value' => 'Rs. '.number_format($totalJobs ? $revenue / $totalJobs : 0, 2), 'detail' => 'Revenue per created job', 'tone' => 'violet'],
                ['label' => 'Average Turnaround', 'value' => number_format($averageHours, 1).' hrs', 'detail' => 'Completed job duration', 'tone' => 'orange'],
            ],
            'technicianPerformance' => $performanceRows->map(fn ($row) => ['name' => $technicianNames[$row->main_technician_id] ?? 'Former technician', 'jobs' => (int) $row->jobs_count, 'amount' => (float) $row->billed_total]),
            'statusCounts' => (clone $jobs)->selectRaw('status, count(*) total')->groupBy('status')->orderByDesc('total')->pluck('total', 'status'),
            'recentCompleted' => JobCard::with('intake.bike.customer', 'technician')->whereIn('status', ['Completed', 'Billed', 'Delivered'])->whereBetween('updated_at', [$start, $end])->latest('updated_at')->limit(8)->get(),
        ]);
    }

    private function intakeRecords(Request $request, array $group, array $page, string $filter): View
    {
        $query = BikeIntake::with('bike.customer', 'bike.technician', 'jobCard.technician', 'jobCard.invoice')->latest();
        $this->applyIntakeFilter($query, $filter);

        if ($request->filled('q')) {
            $term = trim((string) $request->q);
            $query->where(function (Builder $builder) use ($term) {
                $builder->where('intake_number', 'like', "%{$term}%")
                    ->orWhereHas('bike', fn (Builder $bike) => $bike->where('number', 'like', "%{$term}%")->orWhere('model', 'like', "%{$term}%"))
                    ->orWhereHas('bike.customer', fn (Builder $customer) => $customer->where('name', 'like', "%{$term}%")->orWhere('phone', 'like', "%{$term}%"));
            });
        }

        $allIdsQuery = BikeIntake::query();
        $this->applyIntakeFilter($allIdsQuery, $filter);
        $allIds = $allIdsQuery->pluck('id');

        return view('workspace.intake-records', [
            'group' => $group,
            'page' => $page,
            'records' => $query->paginate(15)->withQueryString(),
            'totalRecords' => $allIds->count(),
            'estimatedTotal' => (float) BikeIntake::whereIn('id', $allIds)->sum('estimated_amount'),
            'advanceTotal' => (float) BikeIntake::whereIn('id', $allIds)->sum('advance_payment'),
            'dueTotal' => (float) Invoice::whereHas('jobCard', fn (Builder $job) => $job->whereIn('bike_intake_id', $allIds))->sum('due_amount'),
        ]);
    }

    private function applyIntakeFilter(Builder $query, string $filter): void
    {
        match ($filter) {
            'pending-intakes' => $query->whereDoesntHave('jobCard'),
            'service-jobs' => $query->where('is_service', true),
            'technician-bikes' => $query->whereHas('bike', fn (Builder $bike) => $bike->where('is_technician_bike', true)),
            'due-bike-records' => $query->whereHas('jobCard.invoice', fn (Builder $invoice) => $invoice->where('due_amount', '>', 0)),
            default => null,
        };
    }

    private function jobRecords(Request $request, array $group, array $page, string $status): View
    {
        $query = JobCard::with('intake.bike.customer', 'technician', 'invoice')->latest();
        if ($status === 'Completed') {
            $query->whereIn('status', ['Completed', 'Billed', 'Delivered']);
            $jobIds = JobCard::whereIn('status', ['Completed', 'Billed', 'Delivered'])->pluck('id');
        } else {
            $query->where('status', $status);
            $jobIds = JobCard::where('status', $status)->pluck('id');
        }

        if ($request->filled('q')) {
            $term = trim((string) $request->q);
            $query->where(function (Builder $builder) use ($term) {
                $builder->where('number', 'like', "%{$term}%")
                    ->orWhereHas('intake.bike', fn (Builder $bike) => $bike->where('number', 'like', "%{$term}%"))
                    ->orWhereHas('intake.bike.customer', fn (Builder $customer) => $customer->where('name', 'like', "%{$term}%")->orWhere('phone', 'like', "%{$term}%"));
            });
        }

        return view('workspace.job-records', [
            'group' => $group,
            'page' => $page,
            'status' => $status,
            'records' => $query->paginate(15)->withQueryString(),
            'totalRecords' => $jobIds->count(),
            'estimatedTotal' => (float) JobCard::whereIn('id', $jobIds)->sum('estimated_amount'),
            'billedTotal' => (float) Invoice::whereIn('job_card_id', $jobIds)->sum('total'),
            'dueTotal' => (float) Invoice::whereIn('job_card_id', $jobIds)->sum('due_amount'),
        ]);
    }
}
