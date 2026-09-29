<?php

namespace App\Observers;

use App\Models\BikeIntake;
use App\Models\Invoice;
use App\Models\JobCard;
use App\Models\Payment;
use App\Services\Sms\WorkshopSmsService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class WorkshopSmsObserver
{
    public function created(Model $record): void
    {
        if ($record instanceof BikeIntake) {
            $this->schedule('received', $record);
        } elseif ($record instanceof JobCard) {
            $this->schedule('job_created', $record);
            if ($record->main_technician_id) {
                $this->schedule('assigned', $record);
            }
        } elseif ($record instanceof Invoice && ! in_array($record->invoice_type, ['Quotation', 'Opening Balance'], true)) {
            $this->schedule('billed', $record);
        } elseif ($record instanceof Payment && $this->isReceipt($record)) {
            $this->schedule('payment', $record);
        }
    }

    public function updated(Model $record): void
    {
        if ($record instanceof Payment && $record->wasChanged('status') && $this->isReceipt($record)) {
            $this->schedule('payment', $record);
        }
        if (! $record instanceof JobCard) {
            return;
        }
        if ($record->wasChanged('main_technician_id') && $record->main_technician_id) {
            $this->schedule('assigned', $record);
        }
        if ($record->wasChanged('status')) {
            $event = match ($record->status) {
                'Completed', 'Repair Ready', 'Ready for Delivery' => 'completed',
                'Delivered' => 'delivered',
                'Cancelled' => 'cancelled',
                'Billed' => null,
                default => 'progress',
            };
            if ($event) {
                $this->schedule($event, $record);
            }
        }
    }

    private function schedule(string $event, Model $record): void
    {
        $sentBy = auth()->id();
        // Wait until invoice items, payments and status changes have all committed.
        DB::afterCommit(fn () => app(WorkshopSmsService::class)->safelySend($event, $record, $sentBy));
    }

    private function isReceipt(Payment $payment): bool
    {
        return $payment->status === 'Completed' && (float) $payment->amount > 0
            && ! in_array($payment->method, ['Salary Deduction', 'Manual Clearance', 'Credit/Due'], true);
    }
}
