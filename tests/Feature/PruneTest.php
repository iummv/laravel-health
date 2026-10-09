<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Iummv\HealthEndpoint\Errors\Occurrences;

beforeEach(function () {
    Carbon::setTestNow('2026-10-09 10:00:00');
});

it('deletes rows older than keep_hours', function () {
    $this->occurrence(['message' => 'a', 'occurred_at' => '2026-10-07 09:59:59']);
    $this->occurrence(['message' => 'b', 'occurred_at' => '2026-10-07 10:00:00']);
    $this->occurrence(['message' => 'c', 'occurred_at' => '2026-10-09 09:00:00']);

    $this->artisan('health-endpoint:prune')->assertSuccessful();

    expect(Occurrences::query()->orderBy('id')->pluck('message')->all())->toBe(['b', 'c']);
});

it('honours a longer keep_hours', function () {
    config(['health-endpoint.errors.keep_hours' => 72]);

    $this->occurrence(['message' => 'a', 'occurred_at' => '2026-10-06 09:00:00']);
    $this->occurrence(['message' => 'b', 'occurred_at' => '2026-10-07 09:00:00']);

    $this->artisan('health-endpoint:prune')->assertSuccessful();

    expect(Occurrences::query()->pluck('message')->all())->toBe(['b']);
});

it('never keeps less than the 24 hours a first pull asks for', function () {
    config(['health-endpoint.errors.keep_hours' => 1]);

    $this->occurrence(['message' => 'a', 'occurred_at' => '2026-10-08 09:00:00']);
    $this->occurrence(['message' => 'b', 'occurred_at' => '2026-10-08 11:00:00']);

    $this->artisan('health-endpoint:prune')->assertSuccessful();

    expect(Occurrences::query()->pluck('message')->all())->toBe(['b']);
});

it('swallows a missing table', function () {
    Schema::drop(Occurrences::TABLE);

    $this->artisan('health-endpoint:prune')->assertSuccessful();
});

it('is scheduled daily', function () {
    $events = collect($this->app->make(Schedule::class)->events())
        ->filter(fn ($event) => str_contains($event->command, 'health-endpoint:prune'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('0 0 * * *');
});
