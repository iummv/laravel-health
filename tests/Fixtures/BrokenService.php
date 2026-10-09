<?php

namespace Iummv\HealthEndpoint\Tests\Fixtures;

use Illuminate\Support\Facades\DB;

class BrokenService
{
    public const LINE = 13;

    public function run(): void
    {
        DB::select('select * from a_table_that_does_not_exist');
    }
}
