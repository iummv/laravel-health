<?php

namespace Iummv\HealthEndpoint\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Iummv\HealthEndpoint\Errors\ErrorSummary;
use Iummv\HealthEndpoint\Support\AppName;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

class ErrorsController
{
    public const VERSION = 1;

    public function __invoke(Request $request, ErrorSummary $summary): JsonResponse
    {
        if (! config('health-endpoint.errors.enabled')) {
            throw new NotFoundHttpException;
        }

        $since = $request->query('since');

        try {
            $result = $summary->since($since);
        } catch (Throwable) {
            // The table cannot be read (not migrated yet, or the database is
            // down). Hand the caller's cursor back with nothing new, so the
            // monitor asks again from the same point.
            $result = [
                'cursor' => is_string($since) && $since !== '' && ctype_digit($since) && strlen($since) <= 255 ? $since : '0',
                'errors' => [],
            ];
        }

        // Not cached: the response depends on "since".
        return response()->json([
            'app' => AppName::get(),
            'version' => self::VERSION,
            'cursor' => $result['cursor'],
            'errors' => $result['errors'],
        ]);
    }
}
