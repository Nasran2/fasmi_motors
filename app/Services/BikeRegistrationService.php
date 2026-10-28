<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Bike;
use App\Models\JobCard;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BikeRegistrationService
{
    public static function rules(): array
    {
        return [
            'bike_number' => ['required', 'string', 'max:30', 'regex:/^(?=.*[\pL\pN])[\pL\pN .\/-]+$/u'],
            'bike_number_reason' => 'required|string|min:5|max:500',
        ];
    }

    public function correct(JobCard $job, array $data): void
    {
        try {
            DB::transaction(function () use ($job, $data) {
                $job = JobCard::lockForUpdate()->findOrFail($job->id);
                $bikeId = $job->is_quick_job ? $job->quick_bike_id : $job->intake()->value('bike_id');
                if (! $bikeId) {
                    throw ValidationException::withMessages(['bike_number' => 'Register the bike before correcting its number.']);
                }
                $bike = Bike::lockForUpdate()->findOrFail($bikeId);
                $number = mb_strtoupper(trim($data['bike_number']));
                $duplicate = Bike::withTrashed()->where('number', $number)->where('id', '!=', $bike->id)->lockForUpdate()->first();
                if ($duplicate) {
                    throw ValidationException::withMessages(['bike_number' => 'This number is already registered to another bike. Enter a unique number.']);
                }
                $before = $bike->number;
                if ($before === $number) {
                    return;
                }
                // Keep the same bike ID so every intake, job, bill, reminder and report follows the correction.
                $bike->update(['number' => $number]);
                foreach (JobCard::withTrashed()->where('quick_bike_id', $bike->id)->get() as $quickJob) {
                    JobCard::withoutTimestamps(fn () => $quickJob->update(['quick_bike_details' => array_replace($quickJob->quick_bike_details ?? [], ['number' => $number])]));
                }
                AuditLog::create([
                    'user_id' => auth()->id(), 'event' => 'bike_number_corrected',
                    'auditable_type' => $bike->getMorphClass(), 'auditable_id' => $bike->id,
                    'old_values' => ['number' => $before],
                    'new_values' => ['number' => $number, 'reason' => $data['bike_number_reason'], 'source_job_card_id' => $job->id],
                    'ip_address' => request()->ip(), 'user_agent' => request()->userAgent(),
                ]);
                DB::table('job_card_status_logs')->insert([
                    'job_card_id' => $job->id, 'old_status' => $job->status, 'new_status' => $job->status,
                    'note' => 'Bike number: '.$before.' → '.$number.'. Reason: '.$data['bike_number_reason'],
                    'changed_by' => auth()->id(), 'created_at' => now(), 'updated_at' => now(),
                ]);
            }, 3);
        } catch (QueryException $exception) {
            // The unique database key also protects concurrent registration corrections.
            if (($exception->errorInfo[1] ?? null) === 1062) {
                throw ValidationException::withMessages(['bike_number' => 'This number is already registered to another bike. Enter a unique number.']);
            }
            throw $exception;
        }
    }
}
