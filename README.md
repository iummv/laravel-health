# iummv/health-endpoint

Adds two token-protected JSON routes to a Laravel app for the IUM monitor to poll:

| Route | Polled | Returns |
|---|---|---|
| `GET /health` | every minute | the state of the app's queues |
| `GET /health/errors` | every five minutes | a grouped summary of errors logged since the last pull |

The monitor pulls; the app never pushes. The package makes no outbound requests.

## 1. Requirements

- PHP 8.3 or later
- Laravel 12.19 or later, or Laravel 13
- A database (the app's own is fine) for the error occurrences table
- A cache store (the app's default) for the rate limit, the `/health` cache and the storm guard
- Laravel Horizon is optional. When it is installed, `/health` also reports worker counts.

## 2. Install

Hosting is not decided yet. Until it is, add the repository to the app's `composer.json` (replace the URL):

```json
"repositories": [
    { "type": "vcs", "url": "https://git.example.com/iummv/health-endpoint.git" }
]
```

```bash
composer require iummv/health-endpoint
```

The service provider is auto-discovered. To change defaults beyond the environment values below, publish the config:

```bash
php artisan vendor:publish --tag=health-endpoint-config
```

## 3. Environment

Generate a token (the monitor accepts up to 255 characters):

```bash
php -r "echo bin2hex(random_bytes(32));"
```

| Env | Default | Meaning |
|---|---|---|
| `HEALTH_TOKEN` | none | Shared secret. **With no token, both routes return 404 and nothing is recorded.** |
| `HEALTH_APP` | slug of `APP_NAME` | The `app` field in responses. |
| `HEALTH_PATH` | `health` | Route path. The errors route is `<path>/errors`. Keep it outside `api/`. |
| `HEALTH_QUEUES` | `default` | Queues to report. See below. |
| `HEALTH_CACHE_SECONDS` | `10` | How long the `/health` body is cached. `0` switches caching off. |
| `HEALTH_ERRORS_ENABLED` | `true` | Record errors and serve the errors route. |
| `HEALTH_ERRORS_LEVEL` | `error` | Lowest log level recorded. |
| `HEALTH_ERRORS_CONNECTION` | app default | Database connection for the occurrences table. |
| `HEALTH_ERRORS_KEEP_HOURS` | `48` | Occurrences older than this are deleted. Never less than 24. |
| `HEALTH_ERRORS_MAX_PER_MINUTE` | `120` | Storm guard: occurrences written per minute. `0` switches it off. |

### Queues

Redis cannot list its queues and an empty database queue is invisible, so each app names its queues:

```dotenv
HEALTH_QUEUES=default,sync,redis:reports
```

- A plain name is a queue on the default queue connection.
- `connection:queue` reads the queue from another connection.
- Every entry is a queue **name**, never a driver. `sync` above is a queue called `sync`.
- Names must be unique across the list, even across connections: the monitor keeps only the first entry of a name, so a repeated name is dropped.

## 4. Migrate

```bash
php artisan migrate
```

This creates `health_error_occurrences`. The migration is loaded from the package, so the deploy script's usual `migrate` is enough. To copy it into the app instead: `php artisan vendor:publish --tag=health-endpoint-migrations`.

If `HEALTH_ERRORS_CONNECTION` is set, the table is created on that connection.

Old occurrences are deleted by `php artisan health-endpoint:prune`, which the package adds to the app's schedule daily. It needs the app's scheduler (`schedule:run`) to be running.

## 5. Check by hand

```bash
curl -i -H "X-Health-Token: $HEALTH_TOKEN" https://app.example.com/health
curl -i -H "X-Health-Token: $HEALTH_TOKEN" https://app.example.com/health/errors
curl -i -H "X-Health-Token: $HEALTH_TOKEN" "https://app.example.com/health/errors?since=0"
```

Both answer `200` with JSON. Without the header, or with a wrong token, they answer `404`, exactly like a route that does not exist. More than 30 requests a minute from one IP answer `429`.

## 6. Register in the monitor

Create a monitor of type `health` and set:

- **URL**: the full `https://…/health` address
- **Errors URL**: the full `https://…/health/errors` address
- **Token**: the value of `HEALTH_TOKEN`

Use the final `https` address. The monitor does not follow redirects, so an `http://` or non-canonical host that redirects will not work.

## 7. Response formats (contract v1)

### `GET /health`

```json
{
  "app": "paper",
  "version": 1,
  "status": "ok",
  "timestamp": "2026-10-09T10:00:00Z",
  "queues": [
    {
      "name": "default",
      "connection": "redis",
      "pending": 12,
      "delayed": 0,
      "reserved": 1,
      "failed": 0,
      "oldest_job_age_sec": 4,
      "workers": 3
    }
  ]
}
```

| Field | Meaning |
|---|---|
| `status` | Always `"ok"`. The app does not judge its own health; thresholds live in the monitor. |
| `timestamp` | When the numbers were collected (UTC). With caching this is earlier than the request. |
| `pending` | Jobs ready to run now. |
| `delayed` | Jobs scheduled for later, including retries on backoff. |
| `reserved` | Jobs being run by a worker now. |
| `failed` | Failed jobs for this connection and queue. `null` when the failed-job provider cannot count. |
| `oldest_job_age_sec` | Age of the oldest pending job. `null` when the queue is empty. |
| `workers` | Horizon worker processes for the queue. `null` without Horizon. |

Any number that cannot be read is `null`. A queue whose connection is unreachable is still listed, with `null` counters, and the response is still `200`.

### `GET /health/errors?since=<cursor>`

```json
{
  "app": "paper",
  "version": 1,
  "cursor": "18342",
  "errors": [
    {
      "level": "error",
      "class": "Illuminate\\Database\\QueryException",
      "message": "SQLSTATE[23000]: Integrity constraint violation",
      "file": "app/Services/Submission.php",
      "line": 42,
      "count": 17,
      "first_seen_at": "2026-10-09T09:12:00Z",
      "last_seen_at": "2026-10-09T09:58:00Z"
    }
  ]
}
```

- `cursor` marks the end of this response. The monitor sends it back as `since`.
- Without `since`, the response covers the last 24 hours. With it, only occurrences after that cursor.
- `count` is the number of occurrences in this response's window, not a running total.
- `class`, `file` and `line` are `null` for a log entry that is not an exception.
- At most 200 entries, highest counts first.
- A pull never deletes anything: asking again with the same `since` gives the same answer plus whatever is new.

## 8. What is recorded, and what is never sent

The package listens for every log write (`MessageLogged`) at `HEALTH_ERRORS_LEVEL` or above, on every channel, and stores one row per occurrence with exactly these columns: fingerprint, level, exception class, message (first 500 characters), file, line and time.

- For an exception, `file` and `line` are the first frame inside the application (under the base path, outside `vendor/`), relative to the base path. Without that, every `QueryException` would point at the same framework line.
- For a plain log entry, only the message is kept.

**Never stored and never sent:** stack traces, log context, request data, headers, user ids, environment values, absolute paths.

The message itself is stored as logged. If the app logs personal data in messages (an email in an exception message, for example), that text reaches the monitor. Keep personal data out of log messages; put it in the log context, which this package ignores.

Errors are grouped by the same fingerprint the monitor uses: an exception by class, file and line (its message is ignored), a plain entry by its message with UUIDs, quoted values and numbers replaced. So `Payment 1042 failed` and `Payment 1043 failed` are one entry.

## 9. Decisions and known limits

### Verified against the framework

- **Laravel floor is 12.19, not 12.49.** `pendingSize`, `delayedSize`, `reservedSize` and `creationTimeOfOldestPendingJob` were added to the queue drivers (database, Redis, SQS, Beanstalkd, sync, null) in **v12.19.0**. They were added to the `Illuminate\Contracts\Queue\Queue` contract only in **v13.0.0**; no 12.x release has them on the contract. The package calls them on the resolved connection without relying on the contract, and a driver that lacks them (a third-party driver on Laravel 12) reports `null` counters. The test suite passes on 12.19.3, 12.69.3 and 13.35.0.
- **Horizon's `WorkloadRepository::get()` shape was confirmed** against Horizon v5.50.0 (`RedisWorkloadRepository`): one row per supervised queue set with `name`, `length`, `wait`, `processes` and `split_queues`. Two details differ from the handover's description: `name` has no connection, and a supervisor working several queues yields one row whose `name` is the queues joined by commas (`emails,notifications`). The package matches a queue against each comma-separated part and reports that row's `processes` for each of them, so the same processes are reported under each queue they serve.
- **Redis is tested with a fake queue connection**, not a real server. No Redis was running and the ground rules forbid starting one. The Redis driver's own size methods are framework code and are not exercised here.

### Departures from the handover's recommendations

- **`occurred_at` is a `DATETIME`, not a `TIMESTAMP`.** It holds UTC wall-clock time. MySQL converts `TIMESTAMP` values through the session time zone, which would shift or reject values on a server not running in UTC; `DATETIME` is stored as written.
- **The rate limit is the package's own small middleware**, not Laravel's `throttle`. It uses the same `RateLimiter`, but lets the request through if the cache is down, where `throttle` would answer `500` and mark the app as down.
- **The token is checked before the rate limit.** A caller without the token always gets `404`, never a `429` that would reveal the route. Only requests with the right token are counted.
- **`workers` is `null`, not `0`, when Horizon is installed but no supervisor lists the queue.** The queue may be run by a `queue:work` daemon that a web request cannot see.
- **`/health/errors` answers `200` when the table cannot be read** (not migrated yet, or the database is down), with an empty list and the caller's own `since` as the cursor (`"0"` when there was none). The monitor then asks again from the same point. Side effect: if the very first pull hits this, the next one is `since=0` and returns everything still in the table (up to `keep_hours`), not just 24 hours.
- **`keep_hours` below 24 is treated as 24**, since a first pull asks for that much.
- **The errors route is always registered and answers `404` when `errors.enabled` is off**, rather than not being registered, so the setting works with cached routes.
- **The storm guard falls back to a per-process count** when the cache cannot count, instead of dropping the occurrence.

### Known limits

- **Rolled-back transactions lose occurrences.** A row written inside a database transaction that is rolled back disappears with it. Point `HEALTH_ERRORS_CONNECTION` at a separate connection (it can be the same database under another connection name) to avoid this.
- **If the database is what failed, the occurrence is lost.** The write fails too and is swallowed.
- **A storm is undercounted.** Past `max_per_minute`, occurrences are not written. The monitor's alert fires on the first occurrence, so nothing is missed, only the count.
- **More than 200 groups in one window.** The 200 with the highest counts are sent, the cursor still advances, and the rest are dropped. With fingerprint grouping this should not happen in practice.
- **Concurrent inserts.** A row with a lower id can commit just after a pull read the highest id, and is then skipped. No locking is used.
- **Only what is logged is seen.** Exceptions the app's handler does not report (`dontReport`, most 4xx responses) are not recorded.
- **`workers` covers Horizon only.** Forge-managed `queue:work` daemons are invisible to a web request.
- **The `/health` cache is per app, not per caller**, and the rate limit is shared by both routes per IP.

## Development

```bash
composer install
vendor/bin/pest
vendor/bin/pint
vendor/bin/testbench package:discover   # shows the provider being auto-discovered
```

Tests run on Orchestra Testbench with in-memory SQLite and the array cache. They make no network calls.
