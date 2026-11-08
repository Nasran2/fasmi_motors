<?php

namespace App\Http\Middleware;

use App\Models\Account;
use App\Models\CashierSession;
use App\Services\CashierService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class RequireOpenCashier
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->routeIs('jobs.create', 'jobs.store', 'jobs.quick.*', 'jobs.invoice', 'sales.job-billing.store', 'sales.parts', 'sales.parts.store', 'sales.parts.update')) {
            return $next($request);
        }

        $check = function () use ($request, $next) {
            if (CashierSession::isOpenToday()) {
                return $next($request);
            }
            $active = CashierSession::active();
            if ($active && ! $active->session_date->isToday()) {
                $message = 'The cashier for ' . $active->session_date->format('d M Y') . ' is still open from a previous day. Please close that day and open today’s cashier before creating job cards, quick jobs or parts sales.';
            } else {
                $message = 'The cashier is closed. Please open the cashier before creating job cards, quick jobs or parts sales. Bike intake is still available.';
            }
            if ($request->expectsJson()) {
                return response()->json(['success' => false, 'message' => $message, 'cashier_url' => route('cashier.index')], 423);
            }

            return redirect()->route('cashier.index')->with('error', $message);
        };

        if ($request->isMethod('GET')) {
            return $check();
        }

        Account::ensureSystemAccounts();

        return DB::transaction(function () use ($check) {
            app(CashierService::class)->lock();

            return $check();
        });
    }
}
