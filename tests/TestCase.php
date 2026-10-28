<?php

namespace Tests;

use App\Models\CashierSession;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    protected function withOpenCashier(): static
    {
        // Existing sales scenarios run during an open business day. CashierSessionTest
        // exercises opening, closing and all closed-day restrictions explicitly.
        CashierSession::firstOrCreate(
            ['shop_type' => session('shop_mode', 'workshop'), 'session_date' => today()->toDateString()],
            ['status' => 'open', 'opening_balance' => 0, 'opened_by' => auth()->id(), 'opened_at' => now()],
        );

        return $this;
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
