<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class SetLocale
{
    public function handle(Request $request, Closure $next)
    {
        $lang = $request->header('X-Locale') ?: substr((string) $request->header('Accept-Language'), 0, 2);
        app()->setLocale(in_array($lang, ['ar', 'en'], true) ? $lang : 'ar');
        \App\Services\Access::flushCache(); // per-request memo must not leak between requests

        return $next($request);
    }
}
