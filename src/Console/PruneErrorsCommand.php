<?php

namespace Iummv\HealthEndpoint\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Iummv\HealthEndpoint\Errors\ErrorSummary;
use Iummv\HealthEndpoint\Errors\Occurrences;
use Throwable;

class PruneErrorsCommand extends Command
{
    protected $signature = 'health-endpoint:prune';

    protected $description = 'Delete recorded error occurrences older than the configured hours';

    public function handle(): int
    {
        // Never keep less than a first pull asks for.
        $hours = max(ErrorSummary::FIRST_PULL_HOURS, (int) config('health-endpoint.errors.keep_hours', 48));

        try {
            $deleted = Occurrences::query()
                ->where('occurred_at', '<', Carbon::now('UTC')->subHours($hours)->format(Occurrences::DATE_FORMAT))
                ->delete();
        } catch (Throwable $e) {
            // Written to the console only: logging here would be recorded as an error.
            $this->components->warn('Could not prune error occurrences: '.$e->getMessage());

            return self::SUCCESS;
        }

        $this->components->info("Deleted {$deleted} error occurrences older than {$hours} hours.");

        return self::SUCCESS;
    }
}
