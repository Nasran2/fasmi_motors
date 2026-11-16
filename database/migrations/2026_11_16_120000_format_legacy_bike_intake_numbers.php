<?php

use App\Models\BikeIntake;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Reformat legacy intake numbers to sequential IN-YYYY_MM_DD-001 format.
     */
    public function up(): void
    {
        DB::transaction(function () {
            $intakes = BikeIntake::withoutGlobalScopes()
                ->orderBy('intake_date')
                ->orderBy('created_at')
                ->orderBy('id')
                ->get();

            if ($intakes->isEmpty()) {
                return;
            }

            // Step 1: Temporarily change existing numbers to unique temp IDs to avoid duplicate key collision during rename
            foreach ($intakes as $intake) {
                DB::table('bike_intakes')
                    ->where('id', $intake->id)
                    ->update(['intake_number' => 'TEMP-RENAME-' . $intake->id]);
            }

            // Step 2: Assign sequential numbers grouped by intake date (IN-YYYY_MM_DD-001, 002, ...)
            $grouped = $intakes->groupBy(function ($intake) {
                $date = $intake->intake_date ?? $intake->created_at ?? today();
                return Carbon::parse($date)->format('Y_m_d');
            });

            foreach ($grouped as $dateStr => $dayIntakes) {
                $seq = 1;
                foreach ($dayIntakes as $intake) {
                    $formatted = "IN-{$dateStr}-" . str_pad((string) $seq, 3, '0', STR_PAD_LEFT);
                    DB::table('bike_intakes')
                        ->where('id', $intake->id)
                        ->update(['intake_number' => $formatted]);
                    $seq++;
                }
            }
        });
    }

    public function down(): void
    {
        // Irreversible cosmetic formatting migration.
    }
};
