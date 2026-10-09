<?php

namespace Iummv\HealthEndpoint\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Iummv\HealthEndpoint\Queues\QueueInspector;
use Iummv\HealthEndpoint\Support\AppName;
use Throwable;

class HealthController
{
    public const CACHE_KEY = 'health-endpoint:health';

    public const VERSION = 1;

    public function __invoke(QueueInspector $inspector): JsonResponse
    {
        $seconds = (int) config('health-endpoint.cache_seconds', 10);

        if ($seconds <= 0) {
            return response()->json($this->collect($inspector));
        }

        // If the cache store itself throws, the body is computed and returned uncached.
        try {
            $body = Cache::get(self::CACHE_KEY);
        } catch (Throwable) {
            return response()->json($this->collect($inspector));
        }

        if (! is_array($body)) {
            $body = $this->collect($inspector);

            try {
                Cache::put(self::CACHE_KEY, $body, $seconds);
            } catch (Throwable) {
                //
            }
        }

        return response()->json($body);
    }

    /**
     * @return array<string, mixed>
     */
    protected function collect(QueueInspector $inspector): array
    {
        $timestamp = Carbon::now('UTC')->format('Y-m-d\TH:i:s\Z');

        try {
            $queues = $inspector->inspect();
        } catch (Throwable) {
            $queues = [];
        }

        return [
            'app' => AppName::get(),
            'version' => self::VERSION,
            // The app does not judge its own health; thresholds live in the monitor.
            'status' => 'ok',
            'timestamp' => $timestamp,
            'queues' => $queues,
        ];
    }
}
