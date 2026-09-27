<?php

declare(strict_types=1);

namespace App\Support;

final class Logger
{
    private const LEVELS = ['debug' => 0, 'info' => 1, 'warning' => 2, 'error' => 3];

    /**
     * Centralized sensitive-key classification (AI foundation,
     * docs/10 D-066). A key is sensitive when it contains any of these
     * case-insensitive fragments — this replaces the old exact-match
     * list so AWS-shaped names (aws_access_key_id,
     * aws_secret_access_key, aws_session_token,
     * aws_bearer_token_bedrock, bedrock_api_key, ...) can never be
     * emitted through structured logging just because nobody added an
     * exact spelling.
     */
    private const SENSITIVE_FRAGMENTS = [
        'password',
        'passwd',
        'pwd',
        'secret',
        'token',
        'credential',
        'api_key',
        'apikey',
        'authorization',
        'bearer',
        'session',
        'cookie',
        'access_key',
        'private_key',
        'x-amz',
        'signature',
    ];

    /**
     * Public so tests and other components classify keys the same way
     * the Logger does — no scattered special cases.
     *
     * The only carve-out: plural usage counters (input_tokens,
     * output_tokens, ...) carrying an int/float value are measures,
     * not credential material. Singular names and any string/array
     * value stay redacted.
     */
    public static function isSensitiveKey(string $key, mixed $value = null): bool
    {
        $normalized = strtolower($key);

        if (str_ends_with($normalized, 'tokens') && (is_int($value) || is_float($value))) {
            return false;
        }

        foreach (self::SENSITIVE_FRAGMENTS as $fragment) {
            if (str_contains($normalized, $fragment)) {
                return true;
            }
        }

        return false;
    }

    private static ?Logger $default = null;

    public static function default(?string $basePath = null): self
    {
        if (self::$default !== null) {
            return self::$default;
        }

        $dir = ($basePath ?? dirname(__DIR__, 2)) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
        $level = 'debug';

        try {
            $level = Config::get('APP_LOG_LEVEL', 'debug') ?? 'debug';
        } catch (\Throwable) {
            // Config may not yet be initialized in some contexts.
        }

        self::$default = new self($dir, $level);

        return self::$default;
    }

    public static function setDefault(?Logger $logger): void
    {
        self::$default = $logger;
    }

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

        @file_put_contents(
            $this->directory . DIRECTORY_SEPARATOR . 'app.log',
            $line . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
    }

    private function redact(array $context): array
    {
        $result = [];

        foreach ($context as $key => $value) {
            $isSensitive = is_string($key) && self::isSensitiveKey($key, $value);

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
