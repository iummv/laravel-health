<?php

namespace Iummv\HealthEndpoint;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Iummv\HealthEndpoint\Console\PruneErrorsCommand;
use Iummv\HealthEndpoint\Errors\ErrorRecorder;
use Iummv\HealthEndpoint\Errors\FrameResolver;
use Iummv\HealthEndpoint\Http\Controllers\ErrorsController;
use Iummv\HealthEndpoint\Http\Controllers\HealthController;
use Iummv\HealthEndpoint\Http\Middleware\ThrottleHealthRequests;
use Iummv\HealthEndpoint\Http\Middleware\VerifyHealthToken;

class HealthEndpointServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/health-endpoint.php', 'health-endpoint');

        $this->app->singleton(FrameResolver::class, fn ($app) => new FrameResolver($app->basePath()));
        $this->app->singleton(ErrorRecorder::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->registerRoutes();

        Event::listen(MessageLogged::class, [ErrorRecorder::class, 'handle']);

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/health-endpoint.php' => config_path('health-endpoint.php'),
            ], 'health-endpoint-config');

            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'health-endpoint-migrations');

            $this->commands([PruneErrorsCommand::class]);

            $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
                if (config('health-endpoint.errors.enabled')) {
                    $schedule->command(PruneErrorsCommand::class)->daily();
                }
            });
        }
    }

    protected function registerRoutes(): void
    {
        $path = trim((string) config('health-endpoint.path', 'health'), '/') ?: 'health';

        // No "web" or "api" group: the routes need no session, cookies or CSRF.
        // The token is checked first, so a caller without it only ever sees 404.
        Route::middleware([VerifyHealthToken::class, ThrottleHealthRequests::class])->group(function () use ($path) {
            Route::get($path, HealthController::class)->name('health-endpoint.health');
            Route::get($path.'/errors', ErrorsController::class)->name('health-endpoint.errors');
        });
    }
}
