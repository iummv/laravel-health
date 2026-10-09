<?php

namespace Iummv\LaravelHealth\Queues;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Queue\Failed\CountableFailedJobProvider;
use Illuminate\Support\Carbon;
use Throwable;

class QueueInspector
{
    /**
     * Named as a string so Horizon is never autoloaded when it is absent.
     */
    public const HORIZON_WORKLOAD = 'Laravel\Horizon\Contracts\WorkloadRepository';

    public function __construct(protected Container $app) {}

    /**
     * @return list<array<string, int|string|null>>
     */
    public function inspect(): array
    {
        $workload = $this->horizonWorkload();
        $queues = [];

        foreach (QueueList::parse(config('laravel-health.queues')) as $queue) {
            $queues[] = $this->inspectQueue($queue['connection'], $queue['name'], $workload);
        }

        return $queues;
    }

    /**
     * @param  list<array<string, mixed>>|null  $workload
     * @return array<string, int|string|null>
     */
    protected function inspectQueue(?string $connection, string $name, ?array $workload): array
    {
        $default = config('queue.default');
        $connectionName = $connection ?? (is_string($default) ? $default : null);

        $row = [
            'name' => $name,
            'connection' => $connectionName,
            'pending' => null,
            'delayed' => null,
            'reserved' => null,
            'failed' => null,
            'oldest_job_age_sec' => null,
            'workers' => null,
        ];

        // A queue whose connection is unreachable is still listed, with null counters.
        try {
            $queue = $this->app->make('queue')->connection($connection);
        } catch (Throwable) {
            $queue = null;
        }

        if ($queue !== null) {
            $row['pending'] = $this->counter(fn () => $queue->pendingSize($name));
            $row['delayed'] = $this->counter(fn () => $queue->delayedSize($name));
            $row['reserved'] = $this->counter(fn () => $queue->reservedSize($name));

            $oldest = $this->counter(fn () => $queue->creationTimeOfOldestPendingJob($name));

            if ($oldest !== null) {
                $row['oldest_job_age_sec'] = max(0, Carbon::now()->getTimestamp() - $oldest);
            }
        }

        $row['failed'] = $this->counter(function () use ($connectionName, $name) {
            $failer = $this->app->make('queue.failer');

            return $failer instanceof CountableFailedJobProvider
                ? $failer->count($connectionName, $name)
                : null;
        });

        $row['workers'] = $this->workers($name, $workload);

        return $row;
    }

    /**
     * Run one read and return a whole number of 0 or more, or null.
     */
    protected function counter(Closure $read): ?int
    {
        try {
            $value = $read();
        } catch (Throwable) {
            return null;
        }

        if (is_float($value)) {
            $value = (int) floor($value);
        } elseif (is_string($value) && is_numeric($value)) {
            $value = (int) floor((float) $value);
        }

        return is_int($value) && $value >= 0 ? $value : null;
    }

    /**
     * Horizon's workload: one row per supervised queue set, with "name" (the
     * queue, or several joined by commas, without the connection) and
     * "processes". Null without Horizon or when Horizon cannot be read.
     *
     * @return list<array<string, mixed>>|null
     */
    protected function horizonWorkload(): ?array
    {
        try {
            if (! $this->app->bound(self::HORIZON_WORKLOAD)) {
                return null;
            }

            $rows = $this->app->make(self::HORIZON_WORKLOAD)->get();

            return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  list<array<string, mixed>>|null  $workload
     */
    protected function workers(string $name, ?array $workload): ?int
    {
        if ($workload === null) {
            return null;
        }

        $workers = null;

        foreach ($workload as $row) {
            $names = is_string($row['name'] ?? null) ? explode(',', $row['name']) : [];
            $processes = $row['processes'] ?? null;

            if (in_array($name, $names, true) && is_int($processes) && $processes >= 0) {
                $workers = ($workers ?? 0) + $processes;
            }
        }

        // No Horizon supervisor works this queue. It may still be run by a
        // queue:work daemon the web request cannot see, so this is null, not 0.
        return $workers;
    }
}
