<?php

namespace Iummv\LaravelHealth\Tests\Feature;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Route;
use Iummv\LaravelHealth\LaravelHealthServiceProvider;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Boots Testbench with exactly the providers composer.json advertises under
 * extra.laravel.providers, which is what Laravel's package discovery reads.
 * (`vendor/bin/testbench package:discover` checks the same thing by hand.)
 */
class DiscoveryTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        $composer = json_decode(file_get_contents(dirname(__DIR__, 2).'/composer.json'), true);

        return $composer['extra']['laravel']['providers'];
    }

    #[Test]
    public function the_provider_advertised_in_composer_json_boots_the_package(): void
    {
        $this->assertSame([LaravelHealthServiceProvider::class], $this->getPackageProviders($this->app));
        $this->assertTrue($this->app->providerIsLoaded(LaravelHealthServiceProvider::class));
        $this->assertTrue(Route::has('laravel-health.health'));
        $this->assertTrue(Route::has('laravel-health.errors'));
        $this->assertSame('default', config('laravel-health.queues'));
        $this->assertArrayHasKey('laravel-health:prune', $this->app[Kernel::class]->all());
    }
}
