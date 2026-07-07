<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property array<int, array<string, mixed>>|null $preview_data
 * @property array<int, array{row:int, message:string}>|null $errors
 */
class AttendanceImportBatch extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['month' => 'date', 'preview_data' => 'array', 'errors' => 'array'];
    }

    public function importer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }

    public function attendance(): HasMany
    {
        return $this->hasMany(TechnicianAttendance::class, 'import_batch_id');
    }
}
