<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class PreventDuplicateSubmission
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->input('_submission_token');
        if ($request->isMethod('GET') || ! $request->user() || ! $token) {
            return $next($request);
        }
        $request->validate(['_submission_token' => 'uuid']);
        $key = 'submission:'.hash('sha256', $request->user()->id.'|'.$request->path().'|'.$token);
        try {
            return Cache::lock($key.':lock', 60)->block(5, function () use ($key, $request, $next) {
                if ($cached = Cache::get($key)) {
                    if (is_array($cached)) {
                        return response()->json($cached['data'], $cached['status']);
                    }

                    return redirect($cached)->with('success', 'This submission has already been processed.');
                }
                $response = $next($request);
                if ($response->isRedirection() && ! array_intersect(['errors', 'error'], $request->session()->get('_flash.new', []))) {
                    Cache::put($key, $response->headers->get('Location'), now()->addDay());
                } elseif ($response instanceof JsonResponse && $response->isSuccessful() && data_get($response->getData(true), 'success') === true) {
                    Cache::put($key, ['data' => $response->getData(true), 'status' => $response->getStatusCode()], now()->addDay());
                }

                return $response;
            });
        } catch (LockTimeoutException) {
            return back()->with('error', 'This submission is still processing. Please wait before retrying.');
        }
    }
}
