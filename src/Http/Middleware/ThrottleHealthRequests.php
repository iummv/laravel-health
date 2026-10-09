<?php

namespace Iummv\LaravelHealth\Http\Middleware;

use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class ThrottleHealthRequests
{
    public const MAX_PER_MINUTE = 30;

    public function handle(Request $request, Closure $next): Response
    {
        $key = 'laravel-health:'.sha1((string) $request->ip());

        // The limiter lives in the cache. If the cache is down, let the
        // request through rather than answer the monitor with a 500.
        try {
            $limiter = app(RateLimiter::class);

            if ($limiter->tooManyAttempts($key, self::MAX_PER_MINUTE)) {
                return response()->json(['message' => 'Too Many Attempts.'], 429, [
                    'Retry-After' => $limiter->availableIn($key),
                ]);
            }

            $limiter->hit($key, 60);
        } catch (Throwable) {
            //
        }

        return $next($request);
    }
}
