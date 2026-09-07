<?php

declare(strict_types=1);

namespace App\Support;

final class Logger
{
    private const LEVELS = ['debug' => 0, 'info' => 1, 'warning' => 2, 'error' => 3];
    private const SENSITIVE_KEYS = ['password', 'passwd', 'secret', 'token', 'credential', 'api_key', 'authorization'];

    private string $directory;
    private int $minLevel;

    public function __construct(string $directory, string $minLevel = 'debug')
    {
        $this->directory = $directory;
        $this->minLevel = self::LEVELS[$minLevel] ?? 0;
    }

    public function debug(string $message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    private function log(string $level, string $message, array $context): void
    {
        if ((self::LEVELS[$level] ?? 0) < $this->minLevel) {
            return;
        }

        if (!is_dir($this->directory)) {
            @mkdir($this->directory, 0775, true);
            if (!is_dir($this->directory)) {
                return;
            }
        }

        $record = [
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'level' => $level,
            'request_id' => RequestId::current(),
            'message' => $message,
            'context' => $this->redact($context),
        ];

        $line = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        if ($line === false) {
            return;
        }

        @file_put_contents(
            $this->directory . DIRECTORY_SEPARATOR . 'app-' . gmdate('Y-m-d') . '.log',
            $line . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
    }

    private function redact(array $context): array
    {
        $result = [];

        foreach ($context as $key => $value) {
            $isSensitive = is_string($key)
                && in_array(strtolower($key), self::SENSITIVE_KEYS, true);

            if ($isSensitive) {
                $result[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $result[$key] = $this->redact($value);
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }
}
