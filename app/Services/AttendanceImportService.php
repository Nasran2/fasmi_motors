<?php

namespace App\Services;

use App\Models\AttendanceImportBatch;
use App\Models\Technician;
use App\Models\TechnicianAttendance;
use App\Models\TechnicianSalarySheet;
use App\Support\StaffWorkSchedule;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
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
        $calendarService = app(HolidayService::class);
        $calendar = $calendarService->calendar();

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
                $checkOut = StaffWorkSchedule::resolve($technician)['end'];
                $autoFilledCheckout = true;
            }

            $preview[] = $calendarService->importRow([
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
            ], $calendar);
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

    public function mergePreviewRows(array $originalRows, array $submittedRows, array $removedRows, ?string $manifest = null): array
    {
        if ($manifest !== null) {
            $expected = json_decode($manifest, true, 512, JSON_THROW_ON_ERROR);
            $ids = fn (array $values) => collect($values)->map(fn ($id) => (int) $id)->sort()->values()->all();
            if (! is_array($expected) || ! is_array($expected['active'] ?? null) || ! is_array($expected['removed'] ?? null)
                || $ids($expected['active']) !== $ids(array_column($submittedRows, 'row')) || $ids($expected['removed']) !== $ids($removedRows)) {
                throw ValidationException::withMessages(['rows' => 'The full preview was not submitted. No attendance was imported. Reduce the number of rows or increase the server form-input limit before retrying.']);
            }
        }
        $original = collect($originalRows)->keyBy('row');
        $removed = array_fill_keys(array_map('intval', $removedRows), true);
        foreach ($removedRows as $id) {
            if (! $original->has((int) $id)) {
                throw ValidationException::withMessages(['rows' => 'A deleted row does not belong to this preview. Reload the preview.']);
            }
        }
        $result = $original->reject(fn ($item) => isset($removed[(int) $item['row']]))->all();
        foreach ($submittedRows as $input) {
            $id = (int) $input['row'];
            if (isset($removed[$id])) {
                throw ValidationException::withMessages(['rows' => 'A row cannot be retained and deleted together. Reload the preview.']);
            }
            $item = $original->get($id);
            if (! $item) {
                if (empty($input['is_new']) || empty($input['technician_id'])) {
                    throw ValidationException::withMessages(['rows' => 'Select an existing staff member for every added row.']);
                }
                $staff = Technician::findOrFail($input['technician_id']);
                $item = ['row' => $id, 'is_new' => true, 'name' => $staff->name,
                    'person_id' => $staff->attendance_person_id ?: '', 'department' => $staff->department,
                    'shift' => null, 'timetable' => null, 'source_status' => null, 'break_minutes' => 0,
                    'leave_type' => null, 'check_out' => null, 'auto_filled_checkout' => false];
            }
            foreach (['mark_leave' => 'leave_choice_explicit', 'mark_worked' => 'work_choice_explicit'] as $field => $explicit) {
                if (array_key_exists($field, $input)) {
                    $item[$field] = (bool) $input[$field];
                    $item[$explicit] = true;
                }
            }
            $item['schedule_filled_in'] = (bool) ($input['schedule_filled_in'] ?? false);
            $item['date'] = $input['date'];
            $item['check_in'] = empty($input['check_in']) ? null : Carbon::parse($input['check_in'])->format('H:i:s');
            $checkout = empty($input['check_out']) ? null : Carbon::parse($input['check_out'])->format('H:i:s');
            $item['auto_filled_checkout'] = array_key_exists('auto_filled_checkout', $input)
                ? (bool) $input['auto_filled_checkout'] : ($checkout === $item['check_out'] && ($item['auto_filled_checkout'] ?? false));
            $item['check_out'] = $checkout;
            $item['technician_id'] = empty($input['technician_id']) ? null : (int) $input['technician_id'];
            if (! empty($item['is_new']) && ! $item['technician_id']) {
                throw ValidationException::withMessages(['rows' => 'Select a staff member for every added row.']);
            }
            $item['will_create_technician'] = ! $item['technician_id'];
            $result[$id] = $item;
        }
        if (! $result) {
            throw ValidationException::withMessages(['rows' => 'Keep or add at least one row before confirming the import.']);
        }
        $seen = [];
        foreach ($result as $item) {
            $identity = ! empty($item['technician_id']) ? 'staff:'.$item['technician_id']
                : (filled($item['person_id'] ?? null) ? 'person:'.$item['person_id'] : 'name:'.Str::lower($item['name']));
            $key = $identity.'|'.$item['date'];
            if (isset($seen[$key])) {
                throw ValidationException::withMessages(['rows' => 'Two preview rows have the same staff and date ('.$item['date'].'). Delete one row or change its date before importing.']);
            }
            $seen[$key] = true;
        }

        return collect($result)->sortBy(fn ($item) => $item['date'].'|'.str_pad((string) $item['row'], 12, '0', STR_PAD_LEFT))->values()->all();
    }

    public function reviewRow(array $row, array $calendar, ?array $schedules = null): array
    {
        $schedules ??= StaffWorkSchedule::importSchedules();
        $schedule = $schedules['staff'][$row['technician_id'] ?? ''] ?? $schedules['default'];
        $row = app(HolidayService::class)->importRow($row, $calendar);
        if ($row['shop_day']['closed'] && ! $row['mark_worked']) {
            $row['check_in'] = null;
            $row['check_out'] = null;
            $row['auto_filled_checkout'] = false;
            $row['schedule_filled_in'] = false;
        } else {
            if ($row['mark_worked'] && (empty($row['check_in']) || ! empty($row['schedule_filled_in']))) {
                $row['check_in'] = $schedule['start'];
                $row['schedule_filled_in'] = true;
            }
            if (! empty($row['check_in']) && (empty($row['check_out']) || ! empty($row['auto_filled_checkout']))) {
                $row['check_out'] = $schedule['end'];
                $row['auto_filled_checkout'] = true;
            }
        }
        $row['work_schedule'] = $schedule;

        return app(HolidayService::class)->importRow($row, $calendar);
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

            $calendarService = app(HolidayService::class);
            $calendar = $calendarService->calendar();
            $schedules = StaffWorkSchedule::importSchedules();
            foreach ($batch->preview_data ?? [] as $row) {
                $row = $this->reviewRow($row, $calendar, $schedules);
                if ($row['future_day'] && ! $row['check_in'] && ! $row['check_out'] && ! $row['mark_leave'] && ! $row['shop_day']['closed']) {
                    $skipped++;

                    continue;
                }
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
                        // Resolve generated times again from the locked, final staff mapping.
                        $row = $this->reviewRow($row, app(HolidayService::class)->calendar(), ['default' => StaffWorkSchedule::resolve($technician), 'staff' => []]);
                        $status = $row['mark_leave'] ? 'Paid Leave' : $row['source_status'];
                        if ($row['shop_day']['closed']) {
                            $status = $row['mark_worked'] ? 'Worked on Leave Day' : 'Not Worked';
                        } elseif ($row['mark_worked'] || ($row['check_in'] && in_array(strtolower(trim((string) $status)), ['absent', 'no pay leave'], true))) {
                            $status = 'Present';
                        } elseif (array_key_exists('mark_leave', $row) && ! $row['check_in'] && ! $row['check_out']) {
                            $status = $row['mark_leave'] ? 'Paid Leave' : 'Absent';
                        }
                        $metrics = $calculator->calculateDailyAttendance(
                            $technician,
                            $row['date'],
                            $status,
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
                                'in_time' => $metrics['in_time'] ?? null,
                                'out_time' => $metrics['out_time'] ?? null,
                                'leave_type' => $row['mark_leave'] ? null : ($metrics['leave_type'] ?: $row['leave_type']),
                                'is_manual_entry' => ! empty($row['is_new']),
                                'is_manual_edit' => false,
                                'import_batch_id' => $batch->id,
                                'remarks' => (! empty($row['is_new']) ? 'Added during import of ' : 'Imported from ').$batch->file_name,
                                'note' => $row['mark_worked'] && ! $row['shop_day']['closed'] ? 'Imported attendance · missing fingerprint reviewed, scheduled times used for missing punches' : 'Imported attendance',
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
                'status' => count($errors) > 0 ? 'Completed with errors' : 'Completed',
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
