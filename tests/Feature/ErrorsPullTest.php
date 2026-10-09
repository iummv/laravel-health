<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Iummv\HealthEndpoint\Errors\Occurrences;

beforeEach(function () {
    Carbon::setTestNow('2026-10-09 10:00:00');
});

it('returns cursor 0 and no errors for an empty table', function () {
    $response = $this->errors()->assertOk();

    expect($response->json())->toBe([
        'app' => 'paper-portal',
        'version' => 1,
        'cursor' => '0',
        'errors' => [],
    ])->and($response->getContent())->toContain('"cursor":"0"', '"errors":[]');
});

it('groups occurrences of one error with count, first and last seen and the latest message', function () {
    $exception = ['class' => 'Illuminate\Database\QueryException', 'file' => 'app/Services/Submission.php', 'line' => 42];

    $this->occurrence($exception + ['message' => 'SQLSTATE first', 'occurred_at' => '2026-10-09 09:12:00']);
    $this->occurrence(['message' => 'Payment 1042 failed', 'occurred_at' => '2026-10-09 09:30:00']);
    $this->occurrence($exception + ['message' => 'SQLSTATE second', 'occurred_at' => '2026-10-09 09:40:00']);
    $this->occurrence(['message' => 'Payment 1043 failed', 'level' => 'critical', 'occurred_at' => '2026-10-09 09:45:00']);
    $last = $this->occurrence($exception + ['message' => 'SQLSTATE latest', 'occurred_at' => '2026-10-09 09:58:00']);

    $response = $this->errors()->assertOk()->assertJsonPath('cursor', (string) $last);

    expect($response->json('errors'))->toBe([
        [
            'level' => 'error',
            'class' => 'Illuminate\Database\QueryException',
            'message' => 'SQLSTATE latest',
            'file' => 'app/Services/Submission.php',
            'line' => 42,
            'count' => 3,
            'first_seen_at' => '2026-10-09T09:12:00Z',
            'last_seen_at' => '2026-10-09T09:58:00Z',
        ],
        [
            'level' => 'critical',
            'class' => null,
            'message' => 'Payment 1043 failed',
            'file' => null,
            'line' => null,
            'count' => 2,
            'first_seen_at' => '2026-10-09T09:30:00Z',
            'last_seen_at' => '2026-10-09T09:45:00Z',
        ],
    ]);
});

it('returns only the last 24 hours without since', function () {
    $this->occurrence(['message' => 'Old error', 'occurred_at' => '2026-10-08 09:59:59']);
    $this->occurrence(['message' => 'Old error', 'occurred_at' => '2026-10-07 10:00:00']);
    $this->occurrence(['message' => 'Edge error', 'occurred_at' => '2026-10-08 10:00:00']);
    $last = $this->occurrence(['message' => 'Old error', 'occurred_at' => '2026-10-09 09:00:00']);

    $response = $this->errors()->assertJsonPath('cursor', (string) $last);

    expect(collect($response->json('errors'))->pluck('count', 'message')->all())->toBe([
        'Old error' => 1,
        'Edge error' => 1,
    ]);
});

it('repeats nothing at or before the cursor', function () {
    $this->occurrence(['message' => 'First error']);
    $this->occurrence(['message' => 'First error']);

    $cursor = $this->errors()->assertJsonPath('errors.0.count', 2)->json('cursor');

    expect($cursor)->toBe('2');

    // A second pull with the returned cursor and no new errors.
    $this->errors("since={$cursor}")->assertExactJson([
        'app' => 'paper-portal',
        'version' => 1,
        'cursor' => '2',
        'errors' => [],
    ]);

    $this->travel(3)->minutes();
    $this->occurrence(['message' => 'First error']);
    $this->occurrence(['message' => 'Second error']);

    $response = $this->errors("since={$cursor}")->assertJsonPath('cursor', '4');

    // The count covers this window only, not the running total.
    expect($response->json('errors'))->toHaveCount(2)
        ->and(collect($response->json('errors'))->pluck('count', 'message')->all())->toBe([
            'Second error' => 1,
            'First error' => 1,
        ])
        ->and($response->json('errors.1.first_seen_at'))->toBe('2026-10-09T10:03:00Z');

    // The monitor failed to store that response and asks again: same answer.
    expect($this->errors("since={$cursor}")->json())->toBe($response->json());
});

it('reads rows older than 24 hours when the cursor says so', function () {
    $this->occurrence(['message' => 'Old error', 'occurred_at' => '2026-10-07 10:00:00']);

    $this->errors('since=0')->assertJsonPath('errors.0.count', 1);
    $this->errors()->assertJsonPath('errors', []);
});

it('honours since=0 as a real cursor', function () {
    // A quiet app answered "0" and the monitor sends it back.
    $this->errors('since=0')->assertJsonPath('cursor', '0')->assertJsonPath('errors', []);

    $this->occurrence(['message' => 'Old error', 'occurred_at' => '2026-10-07 10:00:00']);
    $this->occurrence(['message' => 'New error']);

    // since=0 means "everything after id 0", not "the last 24 hours".
    $response = $this->errors('since=0')->assertJsonPath('cursor', '2');

    expect($response->json('errors'))->toHaveCount(2);
    expect($this->errors()->json('errors'))->toHaveCount(1);
});

it('treats an unusable since as absent', function (string $query) {
    $this->occurrence(['message' => 'Old error', 'occurred_at' => '2026-10-07 10:00:00']);
    $this->occurrence(['message' => 'New error']);
    $this->occurrence(['message' => 'New error']);

    $response = $this->errors($query)->assertOk()->assertJsonPath('cursor', '3');

    expect($response->json('errors'))->toHaveCount(1)
        ->and($response->json('errors.0.message'))->toBe('New error')
        ->and($response->json('errors.0.count'))->toBe(2);
})->with([
    'non-numeric' => 'since=abc',
    'negative' => 'since=-1',
    'decimal' => 'since=1.5',
    'mixed' => 'since=2abc',
    'empty' => 'since=',
    'array' => 'since[]=1',
    'above the highest id' => 'since=4',
    'far above the highest id' => 'since=999999999999999999999999999999',
]);

it('returns at most 200 entries, highest counts first', function () {
    $rows = [];

    foreach (range(1, 205) as $i) {
        $rows[] = ['fingerprint' => sha1("e{$i}"), 'level' => 'error', 'message' => "Error e{$i}", 'occurred_at' => '2026-10-09 09:00:00'];
    }

    foreach (array_chunk($rows, 50) as $chunk) {
        Occurrences::query()->insert($chunk);
    }

    $this->occurrence(['fingerprint' => sha1('e150'), 'message' => 'Error e150 again']);
    $this->occurrence(['fingerprint' => sha1('e150'), 'message' => 'Error e150 latest']);
    $this->occurrence(['fingerprint' => sha1('e7'), 'message' => 'Error e7 latest']);

    $response = $this->errors()->assertJsonPath('cursor', '208');
    $errors = $response->json('errors');

    expect($errors)->toHaveCount(200)
        ->and($errors[0])->toMatchArray(['message' => 'Error e150 latest', 'count' => 3])
        ->and($errors[1])->toMatchArray(['message' => 'Error e7 latest', 'count' => 2])
        ->and(array_column($errors, 'count'))->toBe(array_merge([3, 2], array_fill(0, 198, 1)));

    // The cursor still advances past the groups that were dropped.
    $this->errors('since=208')->assertJsonPath('errors', []);
});

it('sends count as a JSON number and timestamps in UTC with Z', function () {
    config(['app.timezone' => 'Indian/Maldives']);
    date_default_timezone_set('Indian/Maldives');

    try {
        Carbon::setTestNow(Carbon::parse('2026-10-09 15:00:00', 'Indian/Maldives'));

        Log::error('Logged at three in the afternoon in Malé');

        $content = $this->errors()->assertOk()->getContent();

        expect($content)->toContain('"count":1,')
            ->toContain('"first_seen_at":"2026-10-09T10:00:00Z"')
            ->toContain('"last_seen_at":"2026-10-09T10:00:00Z"')
            ->toContain('"line":null');
    } finally {
        date_default_timezone_set('UTC');
    }
});

it('never sends a trace, context or request data', function () {
    Log::error('Payment failed', ['user_id' => 4217, 'card' => 'context-secret', 'exception_note' => 'ctx']);

    try {
        throw new RuntimeException('Boom');
    } catch (RuntimeException $e) {
        report($e);
    }

    $response = $this->getJson('/health/errors?probe=request-secret', [
        'X-Health-Token' => self::TOKEN,
        'Cookie' => 'session=request-secret',
    ])->assertOk();

    $allowed = ['level', 'class', 'message', 'file', 'line', 'count', 'first_seen_at', 'last_seen_at'];

    expect(array_keys($response->json()))->toBe(['app', 'version', 'cursor', 'errors'])
        ->and($response->json('errors'))->toHaveCount(2);

    foreach ($response->json('errors') as $error) {
        expect(array_keys($error))->toBe($allowed);
    }

    expect($response->getContent())
        ->not->toContain('context-secret')
        ->not->toContain('request-secret')
        ->not->toContain('4217')
        ->not->toContain('trace')
        ->not->toContain('#0 ')
        ->not->toContain(self::TOKEN)
        ->not->toContain(dirname(__DIR__, 2));

    // Nothing but the contract's columns is stored either.
    expect(Schema::getColumnListing(Occurrences::TABLE))
        ->toBe(['id', 'fingerprint', 'level', 'class', 'message', 'file', 'line', 'occurred_at']);
});

it('is not cached', function () {
    $this->errors()->assertJsonPath('cursor', '0');

    $this->occurrence();

    $this->errors()->assertJsonPath('cursor', '1');
});

it('does not delete rows on a pull', function () {
    $this->occurrence();
    $this->errors();
    $this->errors('since=1');

    expect(Occurrences::query()->count())->toBe(1);
});

it('answers 200 with the caller\'s cursor when the table cannot be read', function () {
    Schema::drop(Occurrences::TABLE);

    $this->errors()->assertOk()->assertExactJson(['app' => 'paper-portal', 'version' => 1, 'cursor' => '0', 'errors' => []]);
    $this->errors('since=18342')->assertOk()->assertJsonPath('cursor', '18342')->assertJsonPath('errors', []);
    $this->errors('since=abc')->assertOk()->assertJsonPath('cursor', '0');
});

it('returns 404 for the errors route when errors are disabled', function () {
    config(['health-endpoint.errors.enabled' => false]);

    $this->errors()->assertNotFound();
    $this->health()->assertOk();
});
