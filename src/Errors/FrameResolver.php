<?php

namespace Iummv\LaravelHealth\Errors;

use Throwable;

class FrameResolver
{
    protected string $basePath;

    public function __construct(string $basePath)
    {
        $this->basePath = rtrim($basePath, '/\\');
    }

    /**
     * The first frame that belongs to the application: under the base path and
     * not under vendor/. An exception's own file and line say where it was
     * constructed, which for a QueryException is always the same framework line.
     *
     * @return array{0: string|null, 1: int|null} file relative to the base path, and line
     */
    public function resolve(Throwable $e): array
    {
        $frames = [['file' => $e->getFile(), 'line' => $e->getLine()], ...$e->getTrace()];

        foreach ($frames as $frame) {
            $file = $frame['file'] ?? null;

            if (is_string($file) && $this->isApplicationFile($file)) {
                return [$this->relative($file), $this->line($frame['line'] ?? null)];
            }
        }

        $file = $e->getFile();

        return [$file === '' ? null : $this->relative($file), $this->line($e->getLine())];
    }

    protected function isApplicationFile(string $file): bool
    {
        return $this->isUnder($file, $this->basePath)
            && ! $this->isUnder($file, $this->basePath.DIRECTORY_SEPARATOR.'vendor');
    }

    protected function isUnder(string $file, string $directory): bool
    {
        return str_starts_with($file, $directory.DIRECTORY_SEPARATOR);
    }

    protected function relative(string $file): string
    {
        if ($this->isUnder($file, $this->basePath)) {
            $file = substr($file, strlen($this->basePath) + 1);
        }

        return str_replace('\\', '/', $file);
    }

    protected function line(mixed $line): ?int
    {
        return is_int($line) && $line >= 0 ? $line : null;
    }
}
