<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // MySQL may have committed the column before the original foreign key failed.
        if (! Schema::hasColumn('payments', 'job_advance_id')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->unsignedBigInteger('job_advance_id')->nullable();
            });
        }

        if (! Schema::hasIndex('payments', ['job_advance_id'])) {
            Schema::table('payments', function (Blueprint $table) {
                $table->index('job_advance_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('payments', 'job_advance_id')) {
            if (collect(Schema::getForeignKeys('payments'))->contains('columns', ['job_advance_id'])) {
                Schema::table('payments', fn (Blueprint $table) => $table->dropForeign(['job_advance_id']));
            }

            if (Schema::hasIndex('payments', 'payments_job_advance_id_index')) {
                Schema::table('payments', fn (Blueprint $table) => $table->dropIndex('payments_job_advance_id_index'));
            }
            Schema::table('payments', fn (Blueprint $table) => $table->dropColumn('job_advance_id'));
        }
    }
};
