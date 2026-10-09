<?php

namespace Iummv\LaravelHealth\Tests\Fixtures;

use Illuminate\Queue\SyncQueue;
use RuntimeException;

/**
 * Stands in for a driver the tests cannot reach, such as Redis.
 */
class FakeQueue extends SyncQueue
{
    /**
     * @param  array<string, array{pending?: mixed, delayed?: mixed, reserved?: mixed, oldest?: mixed}>  $sizes  keyed by queue name
     */
    public function __construct(public array $sizes = [], public bool $throws = false)
    {
        parent::__construct();
    }

    public function pendingSize($queue = null)
    {
        return $this->read($queue, 'pending');
    }

    public function delayedSize($queue = null)
    {
        return $this->read($queue, 'delayed');
    }

    public function reservedSize($queue = null)
    {
        return $this->read($queue, 'reserved');
    }

    public function creationTimeOfOldestPendingJob($queue = null)
    {
        return $this->read($queue, 'oldest');
    }

    protected function read(?string $queue, string $key): mixed
    {
        if ($this->throws) {
            throw new RuntimeException('Connection refused');
        }

        return $this->sizes[$queue][$key] ?? null;
    }
}
