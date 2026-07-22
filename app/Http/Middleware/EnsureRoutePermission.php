<?php

namespace App\Http\Middleware;

use App\Support\RoutePermission;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRoutePermission
{
    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();
        $routeName = $route?->getName();
        $user = $request->user();

        if ($user && $routeName && ! RoutePermission::allows($user, $routeName, $route?->parameter('section'), $route?->parameters() ?? [], $request->query())) {
            abort(Response::HTTP_FORBIDDEN, 'You do not have permission to perform this action.');
        }

        return $next($request);
    }
}
