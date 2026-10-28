<?php

namespace App\Services;

use App\Models\AttendanceImportBatch;
use App\Models\SalarySetting;
use App\Models\Technician;
use App\Models\TechnicianAttendance;
use App\Models\TechnicianSalarySheet;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Csv;

class AttendanceImportService
{
    public function preview(UploadedFile $file, int $userId, ?int $technicianId = null): AttendanceImportBatch
    {
        if (in_array(Str::lower($file->getClientOriginalExtension()), ['csv', 'txt'], true)) {
            $reader = new Csv;
            $reader->setDelimiter(',');
            $sheet = $reader->load($file->getRealPath())->getActiveSheet();
        } else {
            $sheet = IOFactory::load($file->getRealPath())->getActiveSheet();
        }
        $rows = $sheet->toArray(null, true, true, false);
        $header = array_map(fn ($value) => $this->header((string) $value), $rows[0] ?? []);
        $required = ['date', 'check in', 'check out'];
        if (! $technicianId) {
            $required = array_merge($required, ['person id', 'name']);
        }
        foreach ($required as $column) {
            if (! in_array($column, $header, true)) {
                throw new \InvalidArgumentException("Missing required attendance column: {$column}");
            }
        }
        $columns = array_flip($header);
        $preview = [];
        $errors = [];
        $settings = SalarySetting::first();
        $closingTime = $settings && $settings->closing_time ? Carbon::parse($settings->closing_time)->format('H:i:s') : '17:00:00';

        foreach (array_slice($rows, 1) as $offset => $row) {
            $rowNumber = $offset + 2;
            $personId = isset($columns['person id']) ? ltrim(trim((string) ($row[$columns['person id']] ?? '')), "' ") : '';
            $name = isset($columns['name']) ? trim((string) ($row[$columns['name']] ?? '')) : '';
            $dateValue = isset($columns['date']) ? trim((string) ($row[$columns['date']] ?? '')) : '';

            if (str_starts_with(strtolower($personId), 'check-in time:') || ($personId === '' && $dateValue === '' && ! $technicianId)) {
                continue;
            }
            if ((! $technicianId && $name === '') || $dateValue === '') {
                $errors[] = ['row' => $rowNumber, 'message' => 'Name or date is missing.'];

                continue;
            }
            try {
                Validator::make(['date' => $dateValue], ['date' => ['required', 'date']])->validate();
                $date = Carbon::parse($dateValue)->toDateString();
            } catch (\Throwable) {
                $errors[] = ['row' => $rowNumber, 'message' => 'Invalid attendance date.'];

                continue;
            }

            $technician = null;
            $identityWarning = null;
            if ($technicianId) {
                $technician = Technician::find($technicianId);
                $name = $name ?: ($technician ? $technician->name : '');
            } else {
                try {
                    $technician = $this->matchIdentity($personId, $name);
                } catch (\RuntimeException $exception) {
                    $identityWarning = $exception->getMessage();
                }
            }
            $existing = $technician ? TechnicianAttendance::where('technician_id', $technician->id)->whereDate('date', $date)->exists() : false;

            try {
                $checkIn = $this->time($this->cell($row, $columns, 'check in'));
                $checkOut = $this->time($this->cell($row, $columns, 'check out'));
            } catch (\Throwable) {
                $errors[] = ['row' => $rowNumber, 'message' => 'Invalid check-in or checkout time.'];

                continue;
            }
            $autoFilledCheckout = false;

            if ($checkIn && ! $checkOut) {
                $checkOut = $closingTime;
                $autoFilledCheckout = true;
            }

            $preview[] = [
                'row' => $rowNumber,
                'person_id' => $personId,
                'name' => Str::limit($name, 150, ''),
                'department' => $this->cell($row, $columns, 'department'),
                'date' => $date,
                'shift' => $this->cell($row, $columns, 'shift'),
                'timetable' => $this->cell($row, $columns, 'timetable'),
                'source_status' => $this->cell($row, $columns, 'attendance status'),
                'check_in' => $checkIn,
                'check_out' => $checkOut,
                'auto_filled_checkout' => $autoFilledCheckout,
                'break_minutes' => $this->minutes($this->cell($row, $columns, 'break')),
                'leave_type' => $this->cell($row, $columns, 'leave type'),
                'identity_warning' => $identityWarning,
                'technician_id' => $technician?->id,
                'technician_name' => $technician?->name,
                'will_create_technician' => ! $technician,
                'duplicate' => $existing,
            ];
        }

        $month = collect($preview)->pluck('date')->filter()->min();

        return AttendanceImportBatch::create([
            'file_name' => $file->getClientOriginalName(),
            'month' => $month ? Carbon::parse($month)->startOfMonth() : null,
            'imported_by' => $userId,
            'total_rows' => count($preview),
            'duplicate_rows' => collect($preview)->where('duplicate', true)->count(),
            'error_rows' => count($errors),
            'status' => 'Preview',
            'preview_data' => $preview,
            'errors' => $errors,
        ]);
    }

    public function confirm(AttendanceImportBatch $batch, AttendanceCalculationService $calculator, int $userId): AttendanceImportBatch
    {
        return DB::transaction(function () use ($batch, $calculator, $userId) {
            $batch = AttendanceImportBatch::lockForUpdate()->findOrFail($batch->id);
            abort_unless((int) $batch->imported_by === $userId, 403);
            abort_unless($batch->status === 'Preview', 422, 'This import has already been confirmed.');
            $success = 0;
            $skipped = 0;
            $duplicates = 0;
            $errors = $batch->errors ?? [];

            foreach ($batch->preview_data ?? [] as $row) {
                try {
                    DB::transaction(function () use ($row, $batch, $calculator, $userId, &$success, &$duplicates) {
                        $technician = $this->resolveTechnician($row, $userId);
                        $technician = Technician::lockForUpdate()->findOrFail($technician->id);
                        if (TechnicianSalarySheet::where('technician_id', $technician->id)
                            ->whereDate('period_start', '<=', $row['date'])
                            ->whereDate('period_end', '>=', $row['date'])
                            ->whereIn('status', ['Finalized', 'Paid', 'Partially Paid'])
                            ->exists()) {
                            throw new \RuntimeException('Attendance date is already included in a finalized salary period.');
                        }
                        $existing = TechnicianAttendance::where('technician_id', $technician->id)->whereDate('date', $row['date'])->first();
                        if ($existing) {
                            $duplicates++;
                        }
                        $createdBy = $existing ? $existing->created_by : $userId;
                        $metrics = $calculator->calculateDailyAttendance(
                            $technician,
                            $row['date'],
                            $row['source_status'],
                            $row['check_in'],
                            $row['check_out'],
                            ['break_minutes' => $row['break_minutes'], 'auto_filled_checkout' => $row['auto_filled_checkout'] ?? false]
                        );
                        TechnicianAttendance::updateOrCreate(
                            ['technician_id' => $technician->id, 'date' => $row['date']],
                            array_merge($metrics, [
                                'source_person_id' => $row['person_id'] ?: null,
                                'department' => $row['department'] ?: null,
                                'shift_name' => $row['shift'] ?: null,
                                'timetable' => $row['timetable'] ?: null,
                                'in_time' => $row['check_in'],
                                'out_time' => $row['check_out'],
                                'leave_type' => $row['leave_type'] ?: $metrics['leave_type'],
                                'is_manual_entry' => false,
                                'is_manual_edit' => false,
                                'import_batch_id' => $batch->id,
                                'remarks' => 'Imported from '.$batch->file_name,
                                'note' => 'Imported attendance',
                                'created_by' => $createdBy,
                                'edited_by' => $existing ? $userId : null,
                                'updated_by' => $userId,
                            ])
                        );
                        $success++;
                    });
                } catch (\Throwable $exception) {
                    $skipped++;
                    if (count($errors) < 30) {
                        $errors[] = ['row' => $row['row'], 'message' => $exception->getMessage()];
                    }
                }
            }

            $batch->update([
                'success_rows' => $success,
                'skipped_rows' => $skipped,
                'duplicate_rows' => $duplicates,
                'error_rows' => count($errors),
                'errors' => $errors,
                'status' => $skipped > 0 ? 'Completed with errors' : 'Completed',
                'preview_data' => null,
            ]);

            return $batch->fresh();
        }, 3);
    }

    private function resolveTechnician(array $row, int $userId): Technician
    {
        $technician = ! empty($row['technician_id'])
            ? Technician::lockForUpdate()->findOrFail($row['technician_id'])
            : $this->matchIdentity($row['person_id'] ?? '', $row['name']);
        if ($technician) {
            $technician->update(array_filter([
                'attendance_person_id' => $technician->attendance_person_id ?: ($row['person_id'] ?: null),
                'department' => $row['department'] ?: null,
            ], fn ($value) => $value !== null));

            return $technician;
        }

        $phone = 'ATT-'.($row['person_id'] ?: Str::upper(Str::random(8)));
        while (Technician::withTrashed()->where('phone', $phone)->exists()) {
            $phone .= '-'.Str::upper(Str::random(3));
        }

        return Technician::create([
            'attendance_person_id' => $row['person_id'] ?: null,
            'name' => $row['name'],
            'department' => $row['department'] ?: null,
            'phone' => Str::limit($phone, 30, ''),
            'basic_salary' => 0,
            'salary_type' => 'Monthly',
            'commission_percentage' => 0,
            'commission_type' => 'Manual only',
            'active' => true,
            'notes' => 'Created automatically from attendance import by user '.$userId,
        ]);
    }

    private function matchIdentity(string $personId, string $name): ?Technician
    {
        if ($personId !== '') {
            $staff = Technician::where('attendance_person_id', $personId)->first();
            if ($staff) {
                return $staff;
            }
        }
        $matches = Technician::whereRaw('lower(name) = ?', [Str::lower($name)])
            ->when($personId !== '', fn ($query) => $query->where(fn ($query) => $query->whereNull('attendance_person_id')->orWhere('attendance_person_id', '')))
            ->limit(2)->get();
        if ($matches->count() > 1) {
            throw new \RuntimeException('Multiple staff share this name. Select the correct staff member before importing this row.');
        }

        return $matches->first();
    }

    private function header(string $value): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', Str::lower($value)));
    }

    private function cell(array $row, array $columns, string $name): ?string
    {
        if (! isset($columns[$name])) {
            return null;
        }
        $value = trim((string) ($row[$columns[$name]] ?? ''));

        return in_array($value, ['', '-', '—'], true) ? null : $value;
    }

    private function time(?string $value): ?string
    {
        return $value ? Carbon::parse($value)->format('H:i:s') : null;
    }

    private function minutes(?string $value): int
    {
        return $value && preg_match('/-?\d+/', $value, $matches) ? max(0, (int) $matches[0]) : 0;
    }
}
