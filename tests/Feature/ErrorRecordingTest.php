<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Iummv\LaravelHealth\Errors\Fingerprint;
use Iummv\LaravelHealth\Errors\FrameResolver;
use Iummv\LaravelHealth\Errors\Occurrences;
use Iummv\LaravelHealth\Tests\Fixtures\BrokenService;

function occurrences()
{
    return Occurrences::query()->orderBy('id')->get();
}

beforeEach(function () {
    Carbon::setTestNow('2026-10-09 10:00:00');
});

it('records an error-level log entry', function () {
    Log::error('Payment 1042 failed', ['user_id' => 7]);

    $rows = occurrences();

    expect($rows)->toHaveCount(1)
        ->and((array) $rows[0])->toMatchArray([
            'fingerprint' => Fingerprint::make(null, null, null, 'Payment 1042 failed'),
            'level' => 'error',
            'class' => null,
            'message' => 'Payment 1042 failed',
            'file' => null,
            'line' => null,
            'occurred_at' => '2026-10-09 10:00:00',
        ]);
});

it('records a reported exception with its class', function () {
    report(new RuntimeException('Disk is full'));

    $rows = occurrences();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->class)->toBe(RuntimeException::class)
        ->and($rows[0]->message)->toBe('Disk is full')
        ->and($rows[0]->level)->toBe('error')
        ->and($rows[0]->file)->toBe('tests/Feature/ErrorRecordingTest.php')
        ->and($rows[0]->fingerprint)->toBe(Fingerprint::make(RuntimeException::class, $rows[0]->file, (int) $rows[0]->line, 'ignored'));
});

it('does not record a warning', function () {
    Log::warning('Slow query');
    Log::info('Hello');
    Log::debug('Detail');

    expect(occurrences())->toHaveCount(0);
});

it('records levels above error and honours a lower configured level', function () {
    Log::critical('Critical thing');
    Log::emergency('Emergency thing');

    expect(occurrences()->pluck('level')->all())->toBe(['critical', 'emergency']);

    config(['laravel-health.errors.level' => 'warning']);

    Log::warning('Slow query');
    Log::notice('A notice');

    expect(occurrences()->pluck('level')->all())->toBe(['critical', 'emergency', 'warning']);
});

it('uses the class name when an exception has no message', function () {
    report(new LogicException);

    expect(occurrences()[0]->message)->toBe(LogicException::class);
});

it('cuts values to the monitor\'s lengths before fingerprinting', function () {
    Log::error(str_repeat('x', 600));

    $row = occurrences()[0];

    expect(strlen($row->message))->toBe(500)
        ->and($row->fingerprint)->toBe(Fingerprint::make(null, null, null, str_repeat('x', 500)));
});

it('takes file and line from the first application frame', function () {
    try {
        (new BrokenService)->run();
    } catch (QueryException $e) {
        report($e);
    }

    // The exception itself was constructed inside the framework.
    expect($e->getFile())->toContain('/vendor/laravel/framework/');

    $row = occurrences()[0];

    expect($row->class)->toBe(QueryException::class)
        ->and($row->file)->toBe('tests/Fixtures/BrokenService.php')
        ->and((int) $row->line)->toBe(BrokenService::LINE)
        ->and($row->fingerprint)->toBe(sha1(QueryException::class.'|tests/Fixtures/BrokenService.php|'.BrokenService::LINE));
});

it('falls back to the exception\'s own file when no frame belongs to the application', function () {
    $e = new RuntimeException('x');
    $line = __LINE__ - 1;

    expect((new FrameResolver('/nowhere/else'))->resolve($e))->toBe([__FILE__, $line])
        ->and((new FrameResolver(__DIR__))->resolve($e))->toBe(['ErrorRecordingTest.php', $line])
        ->and((new FrameResolver(__DIR__.'/'))->resolve($e))->toBe(['ErrorRecordingTest.php', $line]);
});

it('skips vendor frames under the base path', function () {
    $root = dirname(__DIR__, 2);

    try {
        collect([1])->each(fn () => throw new RuntimeException('x'));
    } catch (RuntimeException $e) {
        //
    }

    // With vendor/ treated as the application, the same trace resolves into the framework.
    [$file] = (new FrameResolver($root))->resolve($e);
    expect($file)->toBe('tests/Feature/ErrorRecordingTest.php');

    $trace = array_column($e->getTrace(), 'file');
    expect(implode("\n", $trace))->toContain($root.'/vendor/');
});

it('swallows a missing table', function () {
    Schema::drop(Occurrences::TABLE);

    Log::error('Still fine');
    report(new RuntimeException('Still fine'));

    expect(Schema::hasTable(Occurrences::TABLE))->toBeFalse();
});

it('does not record an error raised while recording', function () {
    $inner = 0;

    DB::listen(function ($query) use (&$inner) {
        if (str_contains($query->sql, Occurrences::TABLE) && str_starts_with($query->sql, 'insert')) {
            $inner++;
            Log::error('Raised while recording');
        }
    });

    Log::error('Outer error');

    expect($inner)->toBe(1)
        ->and(occurrences()->pluck('message')->all())->toBe(['Outer error']);

    // The guard is released afterwards.
    Log::error('Next error');

    expect($inner)->toBe(2)
        ->and(occurrences()->pluck('message')->all())->toBe(['Outer error', 'Next error']);
});

it('stops writing at the storm guard limit and resumes the next minute', function () {
    config(['laravel-health.errors.max_per_minute' => 5]);

    foreach (range(1, 20) as $i) {
        Log::error("Storm {$i}");
    }

    expect(occurrences())->toHaveCount(5);

    $this->travel(1)->minutes();

    Log::error('After the storm');

    expect(occurrences())->toHaveCount(6);
});

it('still limits per process when the cache cannot count', function () {
    config(['laravel-health.errors.max_per_minute' => 3]);

    Cache::shouldReceive('add')->andThrow(new RuntimeException('Cache is down'));

    foreach (range(1, 10) as $i) {
        Log::error("Storm {$i}");
    }

    expect(occurrences())->toHaveCount(3);
});

it('records nothing when errors are disabled', function () {
    config(['laravel-health.errors.enabled' => false]);

    Log::error('Not recorded');
    report(new RuntimeException('Not recorded'));

    expect(occurrences())->toHaveCount(0);
});

it('records nothing when no token is configured', function () {
    config(['laravel-health.token' => null]);

    Log::error('Not recorded');

    expect(occurrences())->toHaveCount(0);
});

it('writes to the configured connection', function () {
    config([
        'database.connections.health' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
        'laravel-health.errors.connection' => 'health',
    ]);

    $this->artisan('migrate', ['--database' => 'health'])->run();

    Log::error('On the other connection');

    expect(DB::connection('health')->table(Occurrences::TABLE)->count())->toBe(1)
        ->and(DB::connection('testing')->table(Occurrences::TABLE)->count())->toBe(0);

    $this->errors()->assertJsonPath('cursor', '1')->assertJsonPath('errors.0.message', 'On the other connection');
});
