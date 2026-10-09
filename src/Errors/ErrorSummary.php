<?php

namespace Iummv\HealthEndpoint\Errors;

use Illuminate\Support\Carbon;

class ErrorSummary
{
    public const MAX_ENTRIES = 200;

    public const FIRST_PULL_HOURS = 24;

    /**
     * Answer one pull. The cursor is the highest occurrence id covered.
     *
     * @return array{cursor: string, errors: list<array<string, mixed>>}
     */
    public function since(mixed $since): array
    {
        // Read once, before anything else. Rows inserted after this wait for
        // the next pull, or they would be counted twice.
        $upper = (int) Occurrences::query()->max('id');

        $query = Occurrences::query()->where('id', '<=', $upper);
        $after = $this->cursor($since, $upper);

        if ($after === null) {
            $query->where('occurred_at', '>=', Carbon::now('UTC')->subHours(self::FIRST_PULL_HOURS)->format(Occurrences::DATE_FORMAT));
        } else {
            $query->where('id', '>', $after);
        }

        $groups = $query
            ->selectRaw('fingerprint, count(*) as occurrences, min(occurred_at) as first_seen_at, max(occurred_at) as last_seen_at, max(id) as latest_id')
            ->groupBy('fingerprint')
            ->orderByDesc('occurrences')
            ->orderByDesc('latest_id')
            ->limit(self::MAX_ENTRIES)
            ->get();

        // Each group's most recent occurrence supplies its level, class, message, file and line.
        $latest = Occurrences::query()
            ->whereIn('id', $groups->pluck('latest_id')->all())
            ->get()
            ->keyBy('id');

        $errors = [];

        foreach ($groups as $group) {
            $row = $latest->get($group->latest_id);

            if ($row === null) {
                continue;
            }

            $errors[] = [
                'level' => $row->level,
                'class' => $row->class,
                'message' => $row->message,
                'file' => $row->file,
                'line' => $row->line === null ? null : (int) $row->line,
                'count' => (int) $group->occurrences,
                'first_seen_at' => $this->timestamp($group->first_seen_at),
                'last_seen_at' => $this->timestamp($group->last_seen_at),
            ];
        }

        return ['cursor' => (string) $upper, 'errors' => $errors];
    }

    /**
     * The id to read after, or null when the pull is treated as a first pull:
     * "since" absent, not a string of digits, or above the highest id (the
     * table was emptied or restored). "0" is a real cursor, so no empty().
     */
    public function cursor(mixed $since, int $upper): ?int
    {
        if (is_int($since)) {
            $since = (string) $since;
        }

        if (! is_string($since) || $since === '' || ! ctype_digit($since)) {
            return null;
        }

        $digits = ltrim($since, '0');

        // Longer than any id the table can hold.
        if (strlen($digits) > 18) {
            return null;
        }

        $after = (int) $digits;

        return $after > $upper ? null : $after;
    }

    protected function timestamp(mixed $value): string
    {
        return Carbon::parse((string) $value, 'UTC')->format('Y-m-d\TH:i:s\Z');
    }
}
