<?php

declare(strict_types=1);

namespace VolunteerLine;

use DateTimeImmutable;
use DateTimeZone;

final class Logger
{
    private string $logDir;
    private string $timezone;

    public function __construct(string $logDir, string $timezone = 'America/New_York')
    {
        $this->logDir = rtrim($logDir, '/\\');
        $this->timezone = $timezone;

        if (!is_dir($this->logDir)) {
            @mkdir($this->logDir, 0775, true);
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    public function info(string $message, array $context = []): void
    {
        $this->write('INFO', $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function warning(string $message, array $context = []): void
    {
        $this->write('WARN', $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function error(string $message, array $context = []): void
    {
        $this->write('ERROR', $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function write(string $level, string $message, array $context): void
    {
        try {
            $now = new DateTimeImmutable('now', new DateTimeZone($this->timezone));
            $date = $now->format('Y-m-d');
            $timestamp = $now->format('Y-m-d H:i:s T');
            $file = $this->logDir . DIRECTORY_SEPARATOR . "volunteerline-{$date}.log";

            $line = "[{$timestamp}] [{$level}] {$message}";
            if ($context !== []) {
                $encoded = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                if ($encoded !== false) {
                    $line .= ' ' . $encoded;
                }
            }
            $line .= PHP_EOL;

            @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
        } catch (\Throwable $e) {
            @error_log('VolunteerLine Logger error: ' . $e->getMessage());
        }
    }
}
