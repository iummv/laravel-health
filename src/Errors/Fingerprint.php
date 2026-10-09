<?php

namespace Iummv\LaravelHealth\Errors;

class Fingerprint
{
    /**
     * The monitor's fingerprint function, copied as is, so the app's groups
     * match the monitor's. An exception is identified by class, file and line;
     * a plain log entry by its message with UUIDs, quoted values and digit
     * runs replaced.
     */
    public static function make(?string $class, ?string $file, ?int $line, string $message): string
    {
        if ($class !== null) {
            return sha1("{$class}|{$file}|{$line}");
        }

        $normalised = preg_replace(
            ['/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', '/"[^"]*"|\'[^\']*\'/', '/\d+/'],
            ['{uuid}', '{value}', '{n}'],
            $message,
        );

        return sha1($normalised ?? $message);
    }
}
