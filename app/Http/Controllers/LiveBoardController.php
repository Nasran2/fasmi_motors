<?php

namespace App\Http\Controllers;

use App\Models\BikeIntake;
use App\Models\JobCard;
use App\Models\Setting;
use App\Models\Technician;
use App\Services\Sms\TextitSmsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LiveBoardController extends Controller
{
    private const TERMINAL_STATUSES = ['Completed', 'Delivered', 'Cancelled'];

    private const BOARD_STATUSES = [
        'new_intake' => 'Received',
        'diagnosis' => 'Diagnosis',
        'waiting_parts' => 'Waiting for Parts',
        'in_repair' => 'In Progress',
        'washing' => 'Washing / Final Check',
        'ready' => 'Ready for Delivery',
        'on_hold' => 'On Hold',
        'pending_payment' => 'Pending Payment',
    ];

    public function index()
    {
        return view('live-board.index', ['technicians' => Technician::where('active', true)->orderBy('name')->get()]);
    }

    public function fullscreen()
    {
        return view('live-board.fullscreen');
    }

    public function data(Request $request): JsonResponse
    {
        $jobs = JobCard::query()
            ->with(['intake.bike.customer', 'technician', 'parts', 'invoice'])
            ->whereNotIn('status', self::TERMINAL_STATUSES)
            ->when($request->filled('technician_id'), fn ($query) => $query->where('main_technician_id', $request->integer('technician_id')))
            ->when($request->filled('priority'), fn ($query) => $query->where('priority', $request->string('priority')))
            ->when($request->filled('q'), function ($query) use ($request) {
                $term = '%'.$request->string('q')->trim().'%';
                $query->where(function ($nested) use ($term) {
                    $nested->where('number', 'like', $term)
                        ->orWhereHas('technician', fn ($technician) => $technician->where('name', 'like', $term))
                        ->orWhereHas('intake.bike', fn ($bike) => $bike->where('number', 'like', $term)->orWhere('model', 'like', $term))
                        ->orWhereHas('intake.bike.customer', fn ($customer) => $customer->where('name', 'like', $term)->orWhere('phone', 'like', $term));
                });
            })
            ->oldest()
            ->get();

        $cards = $jobs->map(function (JobCard $job) {
            $invoiceDue = (float) ($job->invoice?->due_amount ?? 0);
            $boardStatus = $this->boardStatus($job, $invoiceDue);
            $intake = $job->intake;
            $bike = $intake?->bike;

            return [
                'id' => $job->id,
                'is_intake' => false,
                'number' => $job->number,
                'bike_number' => $bike?->number ?? 'Unknown bike',
                'model' => $bike?->model ?? 'Model not recorded',
                'customer' => $bike?->customer?->name ?? 'Walk-in customer',
                'phone' => $bike?->customer?->phone,
                'complaint' => $intake?->complaint ?? 'No complaint recorded',
                'technician' => $job->technician?->name ?? 'Unassigned',
                'technician_initial' => mb_strtoupper(mb_substr($job->technician?->name ?? 'U', 0, 1)),
                'priority' => $job->priority,
                'status' => $boardStatus,
                'database_status' => $job->status,
                'due' => $intake?->estimated_delivery_date?->format('d M Y'),
                'due_today' => (bool) $intake?->estimated_delivery_date?->isToday(),
                'parts_shortage' => $job->parts->where('out_of_stock', true)->count(),
                'due_amount' => $invoiceDue,
                'job_url' => route('jobs.show', $job),
                'parts_url' => route('jobs.show', $job).'#spare-parts',
                'bill_url' => route('sales.job-billing', ['job' => $job->id]),
                'sms_url' => route('sms.send', ['phone' => $bike?->customer?->phone, 'reference' => $job->number]),
                'updated_at' => $job->updated_at?->toIso8601String(),
            ];
        });

        if (! $request->filled('technician_id') && ! $request->filled('priority')) {
            $intakes = BikeIntake::query()
                ->doesntHave('jobCard')
                ->with('bike.customer')
                ->when($request->filled('q'), function ($query) use ($request) {
                    $term = '%'.$request->string('q')->trim().'%';
                    $query->where(function ($nested) use ($term) {
                        $nested->where('intake_number', 'like', $term)
                            ->orWhereHas('bike', fn ($bike) => $bike->where('number', 'like', $term)->orWhere('model', 'like', $term))
                            ->orWhereHas('bike.customer', fn ($customer) => $customer->where('name', 'like', $term)->orWhere('phone', 'like', $term));
                    });
                })
                ->oldest()
                ->get()
                ->map(function (BikeIntake $intake) {
                    $bike = $intake->bike;

                    return [
                        'id' => 'intake-'.$intake->id,
                        'is_intake' => true,
                        'number' => $intake->intake_number,
                        'bike_number' => $bike?->number ?? 'Unknown bike',
                        'model' => $bike?->model ?? 'Model not recorded',
                        'customer' => $bike?->customer?->name ?? 'Walk-in customer',
                        'phone' => $bike?->customer?->phone,
                        'complaint' => $intake->complaint ?? 'No complaint recorded',
                        'technician' => 'Awaiting assignment',
                        'technician_initial' => '—',
                        'priority' => 'Normal',
                        'status' => 'new_intake',
                        'database_status' => 'New Intake',
                        'due' => $intake->estimated_delivery_date?->format('d M Y'),
                        'due_today' => (bool) $intake->estimated_delivery_date?->isToday(),
                        'parts_shortage' => 0,
                        'due_amount' => 0,
                        'job_url' => route('jobs.create', ['intake' => $intake->id]),
                        'parts_url' => null,
                        'bill_url' => null,
                        'sms_url' => route('sms.send', ['phone' => $bike?->customer?->phone, 'reference' => $intake->intake_number]),
                        'updated_at' => $intake->updated_at?->toIso8601String(),
                    ];
                });

            $cards = $cards->concat($intakes);
        }

        if ($request->filled('status')) {
            $cards = $cards->where('status', $request->string('status')->toString());
        }
        if ($request->boolean('due_today')) {
            $cards = $cards->where('due_today', true);
        }
        if ($request->boolean('waiting_parts')) {
            $cards = $cards->filter(fn ($card) => $card['status'] === 'waiting_parts' || $card['parts_shortage'] > 0);
        }
        if ($request->boolean('payment_pending')) {
            $cards = $cards->filter(fn ($card) => $card['status'] === 'pending_payment' || $card['due_amount'] > 0);
        }

        $groups = collect(array_keys(self::BOARD_STATUSES))->mapWithKeys(fn ($status) => [$status => $cards->where('status', $status)->values()]);

        return response()->json([
            'groups' => $groups,
            'counts' => $groups->map->count(),
            'total' => $cards->count(),
            'updated_at' => now()->toIso8601String(),
        ]);
    }

    public function updateStatus(Request $request, JobCard $job, TextitSmsService $sms): JsonResponse
    {
        $data = $request->validate(['status' => 'required|in:'.implode(',', array_keys(self::BOARD_STATUSES))]);
        $newStatus = self::BOARD_STATUSES[$data['status']];
        $oldStatus = $job->status;

        DB::transaction(function () use ($job, $oldStatus, $newStatus) {
            $job->update(['status' => $newStatus]);
            DB::table('job_card_status_logs')->insert([
                'job_card_id' => $job->id,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
                'note' => 'Updated from Live Bike Status Board',
                'changed_by' => auth()->id(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        if (Setting::valueFor('job_status_sms_enabled', '0') === '1') {
            $job->load('intake.bike.customer');
            $customer = $job->intake?->bike?->customer;
            if ($customer?->phone) {
                $sms->send($customer->phone, "Fasmir Motors: {$job->number} status updated to {$newStatus}.", [
                    'module' => 'Job Card',
                    'message_type' => 'Status Update',
                    'recipient_name' => $customer->name,
                    'recipient_type' => 'customer',
                    'reference_no' => $job->number,
                    'related_type' => $job->getMorphClass(),
                    'related_id' => $job->id,
                    'sent_by' => auth()->id(),
                ]);
            }
        }

        return response()->json(['message' => 'Status updated.', 'status' => $data['status']]);
    }

    private function boardStatus(JobCard $job, float $invoiceDue): string
    {
        if ($invoiceDue > 0 && in_array($job->status, ['Repair Ready', 'Ready for Delivery', 'Billed', 'Pending Payment'], true)) {
            return 'pending_payment';
        }

        return match ($job->status) {
            'Received' => 'new_intake',
            'Assigned', 'Diagnosis' => 'diagnosis',
            'Waiting for Parts' => 'waiting_parts',
            'In Progress' => 'in_repair',
            'Washing / Final Check' => 'washing',
            'Repair Ready', 'Ready for Delivery', 'Billed' => 'ready',
            'On Hold' => 'on_hold',
            'Pending Payment' => 'pending_payment',
            default => 'diagnosis',
        };
    }
}
