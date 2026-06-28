<?php

namespace App\Providers;

use App\Models\AuditLog;
use App\Models\Bike;
use App\Models\BikeIntake;
use App\Models\Drawing;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\JobCard;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Setting;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\Technician;
use App\Models\TechnicianAttendance;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        foreach ([Bike::class, BikeIntake::class, JobCard::class, Invoice::class, Payment::class, Product::class, Purchase::class, PurchaseReturn::class, Supplier::class, SupplierPayment::class, Technician::class, TechnicianAttendance::class, Expense::class, Drawing::class, Setting::class] as $model) {
            $model::created(fn (Model $item) => $this->audit('created', $item, [], $item->getAttributes()));
            $model::updated(fn (Model $item) => $this->audit('updated', $item, $item->getOriginal(), $item->getChanges()));
            $model::deleted(fn (Model $item) => $this->audit('deleted', $item, $item->getOriginal(), []));
        }
    }

    private function audit(string $event, Model $item, array $old, array $new): void
    {
        if (! Schema::hasTable('audit_logs')) {
            return;
        }

        AuditLog::create([
            'user_id' => auth()->id(), 'event' => $event,
            'auditable_type' => $item->getMorphClass(), 'auditable_id' => $item->getKey(),
            'old_values' => $old, 'new_values' => $new,
            'ip_address' => request()->ip(), 'user_agent' => request()->userAgent(),
        ]);
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
