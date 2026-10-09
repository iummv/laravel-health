<?php

namespace Iummv\HealthEndpoint\Queues;

class QueueList
{
    /**
     * Parse the configured queue list.
     *
     * Entries are queue names on the default connection, or "connection:queue".
     * Names are unique across the response, because the monitor keeps only the
     * first entry of a name; later duplicates are dropped here.
     *
     * @param  string|array<int, mixed>|null  $configured
     * @return list<array{connection: string|null, name: string}>
     */
    public static function parse(string|array|null $configured): array
    {
        $entries = is_array($configured) ? $configured : explode(',', (string) $configured);

        $queues = [];

        foreach ($entries as $entry) {
            if (! is_string($entry)) {
                continue;
            }

            $entry = trim($entry);
            $connection = null;

            if (str_contains($entry, ':')) {
                [$connection, $entry] = array_map('trim', explode(':', $entry, 2));
                $connection = $connection === '' ? null : $connection;
            }

            if ($entry === '' || isset($queues[$entry])) {
                continue;
            }

            $queues[$entry] = ['connection' => $connection, 'name' => $entry];
        }

        return array_values($queues);
    }
}
