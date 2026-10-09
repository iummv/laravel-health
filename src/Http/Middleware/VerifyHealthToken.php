<?php

namespace Iummv\LaravelHealth\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class VerifyHealthToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = static::configuredToken();
        $given = $request->header('X-Health-Token');

        // The empty-token check comes first: hash_equals('', '') is true.
        if ($token === null || ! is_string($given) || ! hash_equals($token, $given)) {
            throw new NotFoundHttpException;
        }

        return $next($request);
    }

    public static function configuredToken(): ?string
    {
        $token = config('laravel-health.token');

        return is_string($token) && $token !== '' ? $token : null;
    }
}
