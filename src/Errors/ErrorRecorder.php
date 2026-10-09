<?php

namespace Iummv\LaravelHealth\Errors;

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Iummv\LaravelHealth\Http\Middleware\VerifyHealthToken;
use Throwable;

class ErrorRecorder
{
    protected const LEVELS = [
        'debug' => 0,
        'info' => 1,
        'notice' => 2,
        'warning' => 3,
        'error' => 4,
        'critical' => 5,
        'alert' => 6,
        'emergency' => 7,
    ];

    /**
     * Set while an occurrence is being written, so an error raised by the
     * write itself is not recorded.
     */
    protected bool $recording = false;

    /**
     * Storm-guard fallback for when the cache cannot count: [minute, writes].
     *
     * @var array{0: string, 1: int}
     */
    protected array $localCount = ['', 0];

    public function __construct(protected FrameResolver $frames) {}

    /**
     * Record one log entry. This runs while the app is already failing, so it
     * must never throw and never log.
     */
    public function handle(MessageLogged $event): void
    {
        if ($this->recording) {
            return;
        }

        $this->recording = true;

        try {
            $this->record($event);
        } catch (Throwable) {
            // Swallowed: a missing table, a dead database, anything.
        } finally {
            $this->recording = false;
        }
    }

    protected function record(MessageLogged $event): void
    {
        // With no token configured nobody could read the result.
        if (! config('laravel-health.errors.enabled') || VerifyHealthToken::configuredToken() === null) {
            return;
        }

        $level = strtolower((string) $event->level);
        $threshold = self::LEVELS[strtolower((string) config('laravel-health.errors.level'))] ?? self::LEVELS['error'];

        if (! isset(self::LEVELS[$level]) || self::LEVELS[$level] < $threshold) {
            return;
        }

        $exception = $event->context['exception'] ?? null;

        if ($exception instanceof Throwable) {
            $class = $exception::class;
            $message = $exception->getMessage();
            [$file, $line] = $this->frames->resolve($exception);
        } else {
            $class = $file = $line = null;
            $message = (string) $event->message;
        }

        // Cut to the monitor's lengths before fingerprinting: that is what it hashes.
        $class = $class === null ? null : mb_substr($class, 0, 255);
        $file = $file === null ? null : mb_substr($file, 0, 500);
        $message = mb_substr(trim($message), 0, 500);

        if ($message === '') {
            $message = $class ?? 'Unknown error';
        }

        if ($this->overLimit()) {
            return;
        }

        Occurrences::query()->insert([
            'fingerprint' => Fingerprint::make($class, $file, $line, $message),
            'level' => mb_substr($level, 0, 20),
            'class' => $class,
            'message' => $message,
            'file' => $file,
            'line' => $line,
            'occurred_at' => Carbon::now('UTC')->format(Occurrences::DATE_FORMAT),
        ]);
    }

    /**
     * Storm guard: stop writing once this minute's limit is reached.
     */
    protected function overLimit(): bool
    {
        $max = (int) config('laravel-health.errors.max_per_minute', 120);

        if ($max <= 0) {
            return false;
        }

        $minute = Carbon::now('UTC')->format('YmdHi');

        try {
            $key = 'laravel-health:errors:'.$minute;

            Cache::add($key, 0, 120);

            $count = Cache::increment($key);

            if (is_int($count)) {
                return $count > $max;
            }
        } catch (Throwable) {
            //
        }

        // The cache cannot count: fall back to a limit per process.
        if ($this->localCount[0] !== $minute) {
            $this->localCount = [$minute, 0];
        }

        return ++$this->localCount[1] > $max;
    }
}
