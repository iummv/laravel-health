<?php

namespace Iummv\HealthEndpoint\Support;

use Illuminate\Support\Str;

class AppName
{
    public static function get(): string
    {
        $app = config('health-endpoint.app');

        if (is_string($app) && $app !== '') {
            return $app;
        }

        return Str::slug((string) config('app.name'));
    }
}
