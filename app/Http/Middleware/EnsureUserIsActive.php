<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && (! $user->active || ($user->locked_until && $user->locked_until->isFuture()))) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            if ($request->expectsJson()) {
                abort(Response::HTTP_FORBIDDEN, 'This account is inactive or locked.');
            }

            return redirect()->route('login')->withErrors([
                'login' => 'This account is inactive or temporarily locked.',
            ]);
        }

        return $next($request);
    }
}
