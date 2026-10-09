<?php

namespace Iummv\HealthEndpoint\Tests\Fixtures;

use Illuminate\Queue\Connectors\ConnectorInterface;
use RuntimeException;

class FakeConnector implements ConnectorInterface
{
    public function __construct(protected ?FakeQueue $queue = null) {}

    public function connect(array $config)
    {
        return $this->queue ?? throw new RuntimeException('Connection refused');
    }
}
