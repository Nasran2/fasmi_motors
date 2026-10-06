<?php

namespace App\Providers;

use App\Models\AccountTransaction;
use App\Models\AccountTransfer;
use App\Models\AuditLog;
use App\Models\Bike;
use App\Models\BikeIntake;
use App\Models\Cheque;
use App\Models\DailyClosing;
use App\Models\Drawing;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\JobCard;
use App\Models\LoginHistory;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Setting;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\Technician;
use App\Models\TechnicianAdvance;
use App\Models\TechnicianAttendance;
use App\Models\TechnicianBikeDeduction;
use App\Models\TechnicianCommission;
use App\Models\TechnicianContribution;
use App\Models\TechnicianSalarySheet;
use App\Observers\WorkshopSmsObserver;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
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
        Schema::defaultStringLength(191);
        $this->configureDefaults();

        foreach ([BikeIntake::class, JobCard::class, Invoice::class, Payment::class] as $model) {
            $model::observe(WorkshopSmsObserver::class);
        }

        foreach ([Bike::class, BikeIntake::class, JobCard::class, Invoice::class, Payment::class, Product::class, StockMovement::class, Purchase::class, PurchaseReturn::class, Supplier::class, SupplierPayment::class, Technician::class, TechnicianAttendance::class, TechnicianSalarySheet::class, TechnicianAdvance::class, TechnicianCommission::class, TechnicianBikeDeduction::class, TechnicianContribution::class, Expense::class, Drawing::class, Setting::class, AccountTransaction::class, AccountTransfer::class, DailyClosing::class, Cheque::class] as $model) {
            $model::created(fn (Model $item) => $this->audit('created', $item, [], $item->getAttributes()));
            $model::updated(fn (Model $item) => $this->audit('updated', $item, $item->getOriginal(), $item->getChanges()));
            $model::deleted(fn (Model $item) => $this->audit('deleted', $item, $item->getOriginal(), []));
        }

        // Authentication event logging
        Event::listen(Login::class, function ($event) {
            $user = $event->user;
            $user->update([
                'last_login_at' => now(),
                'last_login_ip' => request()->ip(),
                'failed_login_attempts' => 0,
            ]);

            LoginHistory::create([
                'user_id' => $user->id,
                'username_or_email' => $user->username ?: $user->email,
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'device' => $this->getDevice(request()->userAgent() ?? ''),
                'browser' => $this->getBrowser(request()->userAgent() ?? ''),
                'status' => 'Success',
                'logged_in_at' => now(),
            ]);
        });

        Event::listen(Failed::class, function ($event) {
            $username = $event->credentials['username'] ?? $event->credentials['email'] ?? 'Unknown';
            $user = $event->user;

            if ($user) {
                $user->increment('failed_login_attempts');
                if ($user->failed_login_attempts >= 5) {
                    $user->update(['locked_until' => now()->addMinutes(15)]);
                }
            }

            LoginHistory::create([
                'user_id' => $user?->id,
                'username_or_email' => $username,
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'device' => $this->getDevice(request()->userAgent() ?? ''),
                'browser' => $this->getBrowser(request()->userAgent() ?? ''),
                'status' => $user && $user->failed_login_attempts >= 5 ? 'Locked' : 'Failed',
                'failure_reason' => 'Invalid credentials',
                'logged_in_at' => now(),
            ]);
        });

        Event::listen(Lockout::class, function ($event) {
            $username = $event->request->input('username') ?? $event->request->input('email') ?? 'Unknown';

            LoginHistory::create([
                'user_id' => null,
                'username_or_email' => $username,
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'device' => $this->getDevice(request()->userAgent() ?? ''),
                'browser' => $this->getBrowser(request()->userAgent() ?? ''),
                'status' => 'Locked',
                'failure_reason' => 'Account temporarily locked due to too many failed attempts',
                'logged_in_at' => now(),
            ]);
        });
    }

    private function getDevice(string $ua): string
    {
        if (preg_match('/Mobi|Android|iPhone|iPad/i', $ua)) {
            return 'Mobile';
        }

        return 'Desktop';
    }

    private function getBrowser(string $ua): string
    {
        if (preg_match('/chrome/i', $ua)) {
            return 'Chrome';
        }
        if (preg_match('/firefox/i', $ua)) {
            return 'Firefox';
        }
        if (preg_match('/safari/i', $ua)) {
            return 'Safari';
        }
        if (preg_match('/edge/i', $ua)) {
            return 'Edge';
        }
        if (preg_match('/opera/i', $ua)) {
            return 'Opera';
        }

        return 'Other';
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
