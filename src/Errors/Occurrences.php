<?php

namespace Iummv\LaravelHealth\Errors;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class Occurrences
{
    public const TABLE = 'health_error_occurrences';

    public const DATE_FORMAT = 'Y-m-d H:i:s';

    public static function query(): Builder
    {
        return DB::connection(config('laravel-health.errors.connection'))->table(self::TABLE);
    }
}
