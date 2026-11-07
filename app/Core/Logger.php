<?php

declare(strict_types=1);

namespace App\Core;

/** Journal technique fichier (storage/logs, non accessible depuis le web). */
final class Logger
{
    public function __construct(private readonly string $dir)
    {
    }

    /** @param array<string,mixed> $context */
    public function log(string $level, string $message, array $context = []): void
    {
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0750, true);
        }
        $line = sprintf(
            "[%s] %s: %s %s\n",
            gmdate('Y-m-d\TH:i:s\Z'),
            strtoupper($level),
            str_replace(["\r", "\n"], ' ', $message),
            $context !== [] ? json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) : '',
        );
        @file_put_contents($this->dir . '/app-' . gmdate('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX);
    }

    /** @param array<string,mixed> $context */
    public function error(string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    /** @param array<string,mixed> $context */
    public function info(string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }
}
