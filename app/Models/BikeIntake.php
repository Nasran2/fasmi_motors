<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** @property-read Bike $bike
 * @property-read JobCard|null $jobCard
 */
class BikeIntake extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['intake_date' => 'date', 'is_service' => 'boolean', 'estimated_delivery_date' => 'date', 'next_service_date' => 'date'];
    }

    protected static function booted(): void
    {
        static::creating(function (BikeIntake $intake) {
            $intake->intake_date ??= ($intake->created_at ?? today())->toDateString();
            $intake->intake_number ??= self::generateNumber($intake->intake_date);
        });
    }

    public static function generateNumber(?string $date = null): string
    {
        $date = $date ? Carbon::parse($date) : today();
        $prefix = 'IN-'.$date->format('Y_m_d').'-';

        return DB::transaction(function () use ($prefix) {
            $pattern = '/^'.preg_quote($prefix, '/').'(\d+)$/';
            $max = self::withoutGlobalScopes()
                ->where('intake_number', 'like', $prefix.'%')
                ->lockForUpdate()
                ->pluck('intake_number')
                ->reduce(function (int $max, string $number) use ($pattern) {
                    return preg_match($pattern, $number, $matches) ? max($max, (int) $matches[1]) : $max;
                }, 0);

            $next = $max + 1;
            $candidate = $prefix.str_pad((string) $next, 3, '0', STR_PAD_LEFT);

            while (self::withoutGlobalScopes()->where('intake_number', $candidate)->exists()) {
                $next++;
                $candidate = $prefix.str_pad((string) $next, 3, '0', STR_PAD_LEFT);
            }

            return $candidate;
        });
    }

    public function bike(): BelongsTo
    {
        return $this->belongsTo(Bike::class);
    }

    public function jobCard(): HasOne
    {
        return $this->hasOne(JobCard::class);
    }
}
