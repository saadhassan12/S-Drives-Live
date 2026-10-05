<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Artisan;

class ClearCache
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Never wipe application cache here — it destroys per-driver ride visibility
        // and pending fare-update keys needed for repeat fare increases.
        if (!$request->is('api/driver/near/by/ride')) {
            Artisan::call('config:clear');
            Artisan::call('route:clear');
            Artisan::call('view:clear');
        }

        return $next($request);
    }
}
