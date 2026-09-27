<?php

declare(strict_types=1);

namespace App\Support;

/**
 * CSV attachment response for F8 reporting and data exports.
 *
 * Implements the standard SPMS response interface (status, send, withHeader)
 * while formatting RFC 4180 CSV with correct Content-Type, Content-Disposition,
 * and security headers.
 */
final class CsvResponse
{
    private int $status;
    private array $headers = [];

    private function __construct(
        private readonly string $csv,
        string $filename,
        int $status = 200
    ) {
        $this->status = $status;
        $this->headers['Content-Type'] = 'text/csv; charset=utf-8';
        $this->headers['Content-Disposition'] = 'attachment; filename="' . $filename . '"';
        $this->headers['X-Content-Type-Options'] = 'nosniff';
    }

    public static function create(string $csv, string $filename, int $status = 200): self
    {
        return new self($csv, $filename, $status);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function content(): string
    {
        return $this->csv;
    }

    public function headers(): array
    {
        return $this->headers;
    }

    public function withHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function send(): void
    {
        $headers = $this->headers;

        $requestId = RequestId::current();
        if ($requestId !== null) {
            $headers['X-Request-Id'] = $headers['X-Request-Id'] ?? $requestId;
        }

        if (ob_get_level() > 0 && ob_get_length() > 0) {
            ob_clean();
        }

        if (PHP_SAPI !== 'cli') {
            http_response_code($this->status);

            if (!headers_sent()) {
                foreach ($headers as $name => $value) {
                    header($name . ': ' . $value);
                }
            }
        }

        echo $this->csv;
    }
}
