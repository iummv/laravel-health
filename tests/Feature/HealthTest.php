<?php

use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Iummv\HealthEndpoint\Http\Middleware\ThrottleHealthRequests;
use Iummv\HealthEndpoint\Queues\QueueInspector;
use Iummv\HealthEndpoint\Queues\QueueList;
use Iummv\HealthEndpoint\Tests\Fixtures\FakeConnector;
use Iummv\HealthEndpoint\Tests\Fixtures\FakeQueue;

function fakeConnection(string $name, ?FakeQueue $queue): void
{
    config(["queue.connections.{$name}" => ['driver' => "fake-{$name}"]]);

    Queue::extend("fake-{$name}", fn () => new FakeConnector($queue));
}

beforeEach(function () {
    Carbon::setTestNow('2026-10-09 10:00:00');
});

it('returns the contract shape with every field present', function () {
    $response = $this->health()->assertOk();

    expect($response->json())->toBe([
        'app' => 'paper-portal',
        'version' => 1,
        'status' => 'ok',
        'timestamp' => '2026-10-09T10:00:00Z',
        'queues' => [[
            'name' => 'default',
            'connection' => 'database',
            'pending' => 0,
            'delayed' => 0,
            'reserved' => 0,
            'failed' => 0,
            'oldest_job_age_sec' => null,
            'workers' => null,
        ]],
    ]);
});

it('uses the configured app name', function () {
    config(['health-endpoint.app' => 'paper']);

    $this->health()->assertJsonPath('app', 'paper');
});

it('reports pending, delayed, reserved and oldest job age for the database queue', function () {
    $now = Carbon::now()->getTimestamp();

    $this->job('default', $now - 40);
    $this->job('default', $now - 4);
    $this->job('default', $now + 300);
    $this->job('default', $now - 60, reservedAt: $now - 10);
    $this->job('other', $now - 900);

    $this->health()->assertJsonPath('queues.0', [
        'name' => 'default',
        'connection' => 'database',
        'pending' => 2,
        'delayed' => 1,
        'reserved' => 1,
        'failed' => 0,
        'oldest_job_age_sec' => 40,
        'workers' => null,
    ]);
});

it('reports a null age for an empty queue', function () {
    $this->job('default', Carbon::now()->getTimestamp() + 60);

    $this->health()
        ->assertJsonPath('queues.0.pending', 0)
        ->assertJsonPath('queues.0.delayed', 1)
        ->assertJsonPath('queues.0.oldest_job_age_sec', null);
});

it('counts failed jobs per connection and queue', function () {
    config(['health-endpoint.queues' => 'default,mail']);

    $this->failedJob('database', 'default');
    $this->failedJob('database', 'default');
    $this->failedJob('database', 'mail');
    $this->failedJob('redis', 'default');

    $this->health()
        ->assertJsonPath('queues.0.failed', 2)
        ->assertJsonPath('queues.1.failed', 1);
});

it('reports null failed with a provider that cannot count', function () {
    $this->app->instance('queue.failer', new class implements FailedJobProviderInterface
    {
        public function log($connection, $queue, $payload, $exception) {}

        public function ids($queue = null)
        {
            return [];
        }

        public function all()
        {
            return [];
        }

        public function find($id) {}

        public function forget($id)
        {
            return false;
        }

        public function flush($hours = null) {}
    });

    $this->health()
        ->assertJsonPath('queues.0.failed', null)
        ->assertJsonPath('queues.0.pending', 0);
});

it('reports null workers without Horizon', function () {
    expect($this->app->bound(QueueInspector::HORIZON_WORKLOAD))->toBeFalse();

    $this->health()->assertJsonPath('queues.0.workers', null);
});

it('reports Horizon worker counts, matching on the queue name', function () {
    config(['health-endpoint.queues' => 'default,emails,notifications,reports,unsupervised']);

    // The shape of Laravel\Horizon\Repositories\RedisWorkloadRepository::get().
    $this->app->bind(QueueInspector::HORIZON_WORKLOAD, fn () => new class
    {
        public function get(): array
        {
            return [
                ['name' => 'default', 'length' => 3, 'wait' => 1, 'processes' => 3, 'split_queues' => null],
                ['name' => 'emails,notifications', 'length' => 0, 'wait' => 0, 'processes' => 2, 'split_queues' => []],
                ['name' => 'reports', 'length' => 0, 'wait' => 0, 'processes' => 0, 'split_queues' => null],
            ];
        }
    });

    $this->health()
        ->assertJsonPath('queues.0.workers', 3)
        ->assertJsonPath('queues.1.workers', 2)
        ->assertJsonPath('queues.2.workers', 2)
        ->assertJsonPath('queues.3.workers', 0)
        ->assertJsonPath('queues.4.workers', null);
});

it('reports null workers when Horizon cannot be read', function () {
    $this->app->bind(QueueInspector::HORIZON_WORKLOAD, fn () => new class
    {
        public function get(): array
        {
            throw new RuntimeException('Redis is down');
        }
    });

    $this->health()->assertOk()->assertJsonPath('queues.0.workers', null)->assertJsonPath('queues.0.pending', 0);
});

it('lists a queue whose connection throws with null counters', function () {
    config(['health-endpoint.queues' => 'default,dead:reports,flaky:imports,missing:exports']);

    fakeConnection('dead', null);
    fakeConnection('flaky', new FakeQueue(throws: true));

    $this->job('default', Carbon::now()->getTimestamp() - 5);

    $response = $this->health()->assertOk()->assertJsonPath('queues.0.pending', 1);

    foreach ([1 => ['reports', 'dead'], 2 => ['imports', 'flaky'], 3 => ['exports', 'missing']] as $i => [$name, $connection]) {
        expect($response->json("queues.{$i}"))->toBe([
            'name' => $name,
            'connection' => $connection,
            'pending' => null,
            'delayed' => null,
            'reserved' => null,
            'failed' => 0,
            'oldest_job_age_sec' => null,
            'workers' => null,
        ]);
    }
});

it('reads connection:queue entries from the named connection', function () {
    config(['health-endpoint.queues' => 'default,redis:reports']);

    // Redis is covered with a fake connection: no Redis server is started for tests.
    fakeConnection('redis', new FakeQueue([
        'reports' => ['pending' => 12, 'delayed' => 2, 'reserved' => 1, 'oldest' => Carbon::now()->getTimestamp() - 4],
    ]));

    $this->failedJob('redis', 'reports');

    $this->health()->assertJsonPath('queues.1', [
        'name' => 'reports',
        'connection' => 'redis',
        'pending' => 12,
        'delayed' => 2,
        'reserved' => 1,
        'failed' => 1,
        'oldest_job_age_sec' => 4,
        'workers' => null,
    ]);
});

it('never reports a negative age and nulls anything that is not a whole number', function () {
    config(['health-endpoint.queues' => 'fake:a,fake:b']);

    fakeConnection('fake', new FakeQueue([
        'a' => ['pending' => '7', 'delayed' => -1, 'reserved' => 'n/a', 'oldest' => Carbon::now()->getTimestamp() + 30],
        'b' => ['pending' => 1, 'delayed' => 0, 'reserved' => 0, 'oldest' => (float) Carbon::now()->getTimestamp() - 2.5],
    ]));

    $this->health()
        ->assertJsonPath('queues.0.pending', 7)
        ->assertJsonPath('queues.0.delayed', null)
        ->assertJsonPath('queues.0.reserved', null)
        ->assertJsonPath('queues.0.oldest_job_age_sec', 0)
        ->assertJsonPath('queues.1.oldest_job_age_sec', 3);
});

it('treats every entry as a queue name, including one named sync', function () {
    config(['health-endpoint.queues' => ' default , sync ,, default ']);

    $now = Carbon::now()->getTimestamp();
    $this->job('sync', $now - 9);

    $response = $this->health();

    expect($response->json('queues'))->toHaveCount(2)
        ->and($response->json('queues.1.name'))->toBe('sync')
        ->and($response->json('queues.1.connection'))->toBe('database')
        ->and($response->json('queues.1.pending'))->toBe(1)
        ->and($response->json('queues.1.oldest_job_age_sec'))->toBe(9);
});

it('parses the queue list', function () {
    expect(QueueList::parse('default,sync,redis:reports'))->toBe([
        ['connection' => null, 'name' => 'default'],
        ['connection' => null, 'name' => 'sync'],
        ['connection' => 'redis', 'name' => 'reports'],
    ])
        ->and(QueueList::parse('default,redis:default,:mail,redis:'))->toBe([
            ['connection' => null, 'name' => 'default'],
            ['connection' => null, 'name' => 'mail'],
        ])
        ->and(QueueList::parse(['a', 'sqs:b']))->toBe([
            ['connection' => null, 'name' => 'a'],
            ['connection' => 'sqs', 'name' => 'b'],
        ])
        ->and(QueueList::parse(null))->toBe([]);
});

it('caches the body and shows the collection time', function () {
    config(['health-endpoint.cache_seconds' => 10]);

    $this->health()->assertJsonPath('timestamp', '2026-10-09T10:00:00Z')->assertJsonPath('queues.0.pending', 0);

    $this->job('default', Carbon::now()->getTimestamp() - 1);
    $this->travel(9)->seconds();

    $this->health()->assertJsonPath('timestamp', '2026-10-09T10:00:00Z')->assertJsonPath('queues.0.pending', 0);

    $this->travel(2)->seconds();

    $this->health()->assertJsonPath('timestamp', '2026-10-09T10:00:11Z')->assertJsonPath('queues.0.pending', 1);
});

it('does not cache when cache_seconds is 0', function () {
    config(['health-endpoint.cache_seconds' => 0]);

    $this->health()->assertJsonPath('queues.0.pending', 0);

    $this->job('default', Carbon::now()->getTimestamp() - 1);

    $this->health()->assertJsonPath('queues.0.pending', 1);
});

it('returns the body uncached when the cache store throws', function () {
    Cache::shouldReceive('get')->andThrow(new RuntimeException('Cache is down'));

    $this->withoutMiddleware(ThrottleHealthRequests::class);

    $this->health()->assertOk()->assertJsonPath('queues.0.name', 'default');
});

it('lets the request through when the rate limiter cache throws', function () {
    config(['cache.default' => 'broken', 'cache.stores.broken' => ['driver' => 'broken']]);

    Cache::extend('broken', fn () => throw new RuntimeException('Cache is down'));

    $this->health()->assertOk()->assertJsonPath('queues.0.pending', 0);
});
