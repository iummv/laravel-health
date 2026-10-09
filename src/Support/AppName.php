<?php

namespace Iummv\LaravelHealth\Support;

use Illuminate\Support\Str;

class AppName
{
    public static function get(): string
    {
        $app = config('laravel-health.app');

        if (is_string($app) && $app !== '') {
            return $app;
        }

        return Str::slug((string) config('app.name'));
    }
}
