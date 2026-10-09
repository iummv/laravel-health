<?php

namespace Iummv\LaravelHealth\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Iummv\LaravelHealth\Errors\ErrorRecorder;
use Iummv\LaravelHealth\Errors\Fingerprint;
use Iummv\LaravelHealth\Errors\FrameResolver;
use Iummv\LaravelHealth\Errors\Occurrences;
use Iummv\LaravelHealth\LaravelHealthServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    public const TOKEN = 'test-token-0123456789abcdef';

    protected function setUp(): void
    {
        parent::setUp();

        // The package root stands in for the application's base path, so files
        // under tests/ count as application frames and vendor/ does not.
        $this->app->instance(FrameResolver::class, new FrameResolver(dirname(__DIR__)));
        $this->app->forgetInstance(ErrorRecorder::class);

        $this->artisan('migrate')->run();

        Schema::create('jobs', function (Blueprint $table) {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });

        Schema::create('failed_jobs', function (Blueprint $table) {
            $table->id();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });
    }

    protected function getPackageProviders($app): array
    {
        return [LaravelHealthServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.name', 'Paper Portal');
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('logging.default', 'null');
        $app['config']->set('queue.default', 'database');
        $app['config']->set('queue.connections.database', [
            'driver' => 'database',
            'connection' => 'testing',
            'table' => 'jobs',
            'queue' => 'default',
            'retry_after' => 90,
        ]);
        $app['config']->set('queue.failed', [
            'driver' => 'database',
            'database' => 'testing',
            'table' => 'failed_jobs',
        ]);
        $app['config']->set('laravel-health.token', self::TOKEN);
    }

    protected function health(array $headers = ['X-Health-Token' => self::TOKEN]): TestResponse
    {
        return $this->getJson('/health', $headers);
    }

    protected function errors(?string $query = null, array $headers = ['X-Health-Token' => self::TOKEN]): TestResponse
    {
        return $this->getJson('/health/errors'.($query === null ? '' : '?'.$query), $headers);
    }

    /**
     * Put a job row straight into the database queue's table.
     */
    protected function job(string $queue, int $availableAt, ?int $reservedAt = null): void
    {
        DB::table('jobs')->insert([
            'queue' => $queue,
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => $reservedAt,
            'available_at' => $availableAt,
            'created_at' => $availableAt,
        ]);
    }

    protected function failedJob(string $connection, string $queue): void
    {
        DB::table('failed_jobs')->insert([
            'connection' => $connection,
            'queue' => $queue,
            'payload' => '{}',
            'exception' => 'x',
        ]);
    }

    /**
     * Insert one occurrence row and return its id.
     */
    protected function occurrence(array $attributes = []): int
    {
        $row = array_merge([
            'level' => 'error',
            'class' => null,
            'message' => 'Something broke',
            'file' => null,
            'line' => null,
            'occurred_at' => Carbon::now('UTC'),
        ], $attributes);

        $row['fingerprint'] ??= Fingerprint::make($row['class'], $row['file'], $row['line'], $row['message']);
        $row['occurred_at'] = Carbon::parse($row['occurred_at'])->utc()->format(Occurrences::DATE_FORMAT);

        return (int) Occurrences::query()->insertGetId($row);
    }
}
