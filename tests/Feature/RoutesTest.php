<?php

use Illuminate\Support\Facades\Route;
use Iummv\HealthEndpoint\Http\Middleware\ThrottleHealthRequests;
use Iummv\HealthEndpoint\Tests\TestCase;

dataset('routes', ['/health', '/health/errors']);

it('returns 404 without a token header', function (string $uri) {
    $this->getJson($uri)->assertNotFound();
})->with('routes');

it('returns 404 with a wrong token', function (string $uri) {
    $this->getJson($uri, ['X-Health-Token' => 'wrong'])->assertNotFound();
    $this->getJson($uri, ['X-Health-Token' => TestCase::TOKEN.'x'])->assertNotFound();
})->with('routes');

it('returns 404 for everyone when no token is configured', function (string $uri, mixed $configured) {
    config(['health-endpoint.token' => $configured]);

    $this->getJson($uri)->assertNotFound();
    $this->getJson($uri, ['X-Health-Token' => ''])->assertNotFound();
    $this->getJson($uri, ['X-Health-Token' => TestCase::TOKEN])->assertNotFound();
})->with('routes')->with([null, '']);

it('returns 200 JSON with the right token', function (string $uri) {
    $this->getJson($uri, ['X-Health-Token' => TestCase::TOKEN])
        ->assertOk()
        ->assertHeader('Content-Type', 'application/json');
})->with('routes');

it('accepts GET only', function (string $uri) {
    $this->postJson($uri, [], ['X-Health-Token' => TestCase::TOKEN])->assertStatus(405);
})->with('routes');

it('registers the routes outside api/ and without the web group', function () {
    foreach (['health-endpoint.health' => 'health', 'health-endpoint.errors' => 'health/errors'] as $name => $uri) {
        $route = Route::getRoutes()->getByName($name);

        expect($route)->not->toBeNull()
            ->and($route->uri())->toBe($uri)
            ->and($route->uri())->not->toStartWith('api/')
            ->and($route->methods())->toBe(['GET', 'HEAD'])
            ->and($route->gatherMiddleware())->not->toContain('web')
            ->and($route->gatherMiddleware())->not->toContain('api');
    }
});

it('rate limits to 30 requests a minute per IP', function (string $uri) {
    for ($i = 0; $i < ThrottleHealthRequests::MAX_PER_MINUTE; $i++) {
        $this->getJson($uri, ['X-Health-Token' => TestCase::TOKEN])->assertOk();
    }

    $this->getJson($uri, ['X-Health-Token' => TestCase::TOKEN])
        ->assertStatus(429)
        ->assertHeader('Retry-After');

    // A caller without the token still sees a 404, never the 429.
    $this->getJson($uri)->assertNotFound();

    $this->travel(61)->seconds();

    $this->getJson($uri, ['X-Health-Token' => TestCase::TOKEN])->assertOk();
})->with('routes');
